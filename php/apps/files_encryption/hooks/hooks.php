<?php

/**
 * ownCloud
 *
 * @author Sam Tuke
 * @copyright 2012 Sam Tuke samtuke@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Encryption;

use OC\Files\Filesystem;

/**
 * Class for hook specific logic
 */
class Hooks {

	// TODO: use passphrase for encrypting private key that is separate to 
	// the login password

	/**
	 * @brief Startup encryption backend upon user login
	 * @note This method should never be called for users using client side encryption
	 */
	public static function login($params) {

		// Manually initialise Filesystem{} singleton with correct 
		// fake root path, in order to avoid fatal webdav errors
		// NOTE: disabled because this give errors on webdav!
		//\OC\Files\Filesystem::init( $params['uid'], '/' . 'files' . '/' );

		$view = new \OC_FilesystemView('/');

		// ensure filesystem is loaded
		if(!\OC\Files\Filesystem::$loaded) {
			\OC_Util::setupFS($params['uid']);
		}

		$util = new Util($view, $params['uid']);

		// setup user, if user not ready force relogin
		if (Helper::setupUser($util, $params['password']) === false) {
			return false;
		}

		$encryptedKey = Keymanager::getPrivateKey($view, $params['uid']);

		$privateKey = Crypt::symmetricDecryptFileContent($encryptedKey, $params['password']);

		$session = new \OCA\Encryption\Session($view);

		$session->setPrivateKey($privateKey, $params['uid']);

		// Check if first-run file migration has already been performed
		$migrationCompleted = $util->getMigrationStatus();

		// If migration not yet done
		if (!$migrationCompleted) {

			$userView = new \OC_FilesystemView('/' . $params['uid']);

			// Set legacy encryption key if it exists, to support 
			// depreciated encryption system
			if (
				$userView->file_exists('encryption.key')
				&& $encLegacyKey = $userView->file_get_contents('encryption.key')
			) {

				$plainLegacyKey = Crypt::legacyBlockDecrypt($encLegacyKey, $params['password']);

				$session->setLegacyKey($plainLegacyKey);

			}

			// Encrypt existing user files:
			// This serves to upgrade old versions of the encryption
			// app (see appinfo/spec.txt)
			if (
				$util->encryptAll('/' . $params['uid'] . '/' . 'files', $session->getLegacyKey(), $params['password'])
			) {

				\OC_Log::write(
					'Encryption library', 'Encryption of existing files belonging to "' . $params['uid'] . '" completed'
					, \OC_Log::INFO
				);

			}

			// Register successful migration in DB
			$util->setMigrationStatus(1);

		}

		return true;

	}

	/**
	 * @brief setup encryption backend upon user created
	 * @note This method should never be called for users using client side encryption
	 */
	public static function postCreateUser($params) {
		$view = new \OC_FilesystemView('/');

		$util = new Util($view, $params['uid']);

		Helper::setupUser($util, $params['password']);
	}

	/**
	 * @brief cleanup encryption backend upon user deleted
	 * @note This method should never be called for users using client side encryption
	 */
	public static function postDeleteUser($params) {
		$view = new \OC_FilesystemView('/');

		// cleanup public key
		$publicKey = '/public-keys/' . $params['uid'] . '.public.key';

		// Disable encryption proxy to prevent recursive calls
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$view->unlink($publicKey);

		\OC_FileProxy::$enabled = $proxyStatus;
	}

	/**
	 * @brief Change a user's encryption passphrase
	 * @param array $params keys: uid, password
	 */
	public static function setPassphrase($params) {

		// Only attempt to change passphrase if server-side encryption
		// is in use (client-side encryption does not have access to 
		// the necessary keys)
		if (Crypt::mode() === 'server') {

			if ($params['uid'] === \OCP\User::getUser()) {

				$view = new \OC_FilesystemView('/');

				$session = new \OCA\Encryption\Session($view);

				// Get existing decrypted private key
				$privateKey = $session->getPrivateKey();

				// Encrypt private key with new user pwd as passphrase
				$encryptedPrivateKey = Crypt::symmetricEncryptFileContent($privateKey, $params['password']);

				// Save private key
				Keymanager::setPrivateKey($encryptedPrivateKey);

				// NOTE: Session does not need to be updated as the
				// private key has not changed, only the passphrase
				// used to decrypt it has changed


			} else { // admin changed the password for a different user, create new keys and reencrypt file keys

				$user = $params['uid'];
				$recoveryPassword = $params['recoveryPassword'];
				$newUserPassword = $params['password'];

				$view = new \OC_FilesystemView('/');

				// make sure that the users home is mounted
				\OC\Files\Filesystem::initMountPoints($user);

				$keypair = Crypt::createKeypair();

				// Disable encryption proxy to prevent recursive calls
				$proxyStatus = \OC_FileProxy::$enabled;
				\OC_FileProxy::$enabled = false;

				// Save public key
				$view->file_put_contents('/public-keys/' . $user . '.public.key', $keypair['publicKey']);

				// Encrypt private key empty passphrase
				$encryptedPrivateKey = Crypt::symmetricEncryptFileContent($keypair['privateKey'], $newUserPassword);

				// Save private key
				$view->file_put_contents(
					'/' . $user . '/files_encryption/' . $user . '.private.key', $encryptedPrivateKey);

				if ($recoveryPassword) { // if recovery key is set we can re-encrypt the key files
					$util = new Util($view, $user);
					$util->recoverUsersFiles($recoveryPassword);
				}

				\OC_FileProxy::$enabled = $proxyStatus;
			}
		}
	}

	/*
	 * @brief check if files can be encrypted to every user.
	 */
	/**
	 * @param $params
	 */
	public static function preShared($params) {

		$users = array();
		$view = new \OC\Files\View('/public-keys/');

		switch ($params['shareType']) {
			case \OCP\Share::SHARE_TYPE_USER:
				$users[] = $params['shareWith'];
				break;
			case \OCP\Share::SHARE_TYPE_GROUP:
				$users = \OC_Group::usersInGroup($params['shareWith']);
				break;
		}

		$error = false;
		foreach ($users as $user) {
			if (!$view->file_exists($user . '.public.key')) {
				$error = true;
				break;
			}
		}

		if ($error) // Set flag var 'run' to notify emitting
			// script that hook execution failed
		{
			$params['run']->run = false;
		}
		// TODO: Make sure files_sharing provides user
		// feedback on failed share
	}

	/**
	 * @brief
	 */
	public static function postShared($params) {

		// NOTE: $params has keys:
		// [itemType] => file
		// itemSource -> int, filecache file ID
		// [parent] => 
		// [itemTarget] => /13
		// shareWith -> string, uid of user being shared to
		// fileTarget -> path of file being shared
		// uidOwner -> owner of the original file being shared
		// [shareType] => 0
		// [shareWith] => test1
		// [uidOwner] => admin
		// [permissions] => 17
		// [fileSource] => 13
		// [fileTarget] => /test8
		// [id] => 10
		// [token] =>
		// [run] => whether emitting script should continue to run
		// TODO: Should other kinds of item be encrypted too?

		if ($params['itemType'] === 'file' || $params['itemType'] === 'folder') {

			$view = new \OC_FilesystemView('/');
			$session = new \OCA\Encryption\Session($view);
			$userId = \OCP\User::getUser();
			$util = new Util($view, $userId);
			$path = $util->fileIdToPath($params['itemSource']);

			$share = $util->getParentFromShare($params['id']);
			//if parent is set, then this is a re-share action
			if ($share['parent'] !== null) {

				// get the parent from current share
				$parent = $util->getShareParent($params['parent']);

				// if parent is file the it is an 1:1 share
				if ($parent['item_type'] === 'file') {

					// prefix path with Shared
					$path = '/Shared' . $parent['file_target'];
				} else {

					// NOTE: parent is folder but shared was a file!
					// we try to rebuild the missing path
					// some examples we face here
					// user1 share folder1 with user2 folder1 has 
					// the following structure 
					// /folder1/subfolder1/subsubfolder1/somefile.txt
					// user2 re-share subfolder2 with user3
					// user3 re-share somefile.txt user4
					// so our path should be 
					// /Shared/subfolder1/subsubfolder1/somefile.txt 
					// while user3 is sharing

					if ($params['itemType'] === 'file') {
						// get target path
						$targetPath = $util->fileIdToPath($params['fileSource']);
						$targetPathSplit = array_reverse(explode('/', $targetPath));

						// init values
						$path = '';
						$sharedPart = ltrim($parent['file_target'], '/');

						// rebuild path
						foreach ($targetPathSplit as $pathPart) {
							if ($pathPart !== $sharedPart) {
								$path = '/' . $pathPart . $path;
							} else {
								break;
							}
						}
						// prefix path with Shared
						$path = '/Shared' . $parent['file_target'] . $path;
					} else {
						// prefix path with Shared
						$path = '/Shared' . $parent['file_target'] . $params['fileTarget'];
					}
				}
			}

			$sharingEnabled = \OCP\Share::isEnabled();

			// get the path including mount point only if not a shared folder
			if(strncmp($path, '/Shared' , strlen('/Shared') !== 0)) {
				// get path including the the storage mount point
				$path = $util->getPathWithMountPoint($params['itemSource']);
			}

			// if a folder was shared, get a list of all (sub-)folders
			if ($params['itemType'] === 'folder') {
				$allFiles = $util->getAllFiles($path);
			} else {
				$allFiles = array($path);
			}

			foreach ($allFiles as $path) {
				$usersSharing = $util->getSharingUsersArray($sharingEnabled, $path);
				$util->setSharedFileKeyfiles($session, $usersSharing, $path);
			}
		}
	}

	/**
	 * @brief
	 */
	public static function postUnshare($params) {

		// NOTE: $params has keys:
		// [itemType] => file
		// [itemSource] => 13
		// [shareType] => 0
		// [shareWith] => test1
		// [itemParent] =>

		if ($params['itemType'] === 'file' || $params['itemType'] === 'folder') {

			$view = new \OC_FilesystemView('/');
			$userId = \OCP\User::getUser();
			$util = new Util($view, $userId);
			$path = $util->fileIdToPath($params['itemSource']);

			// check if this is a re-share
			if ($params['itemParent']) {

				// get the parent from current share
				$parent = $util->getShareParent($params['itemParent']);

				// get target path
				$targetPath = $util->fileIdToPath($params['itemSource']);
				$targetPathSplit = array_reverse(explode('/', $targetPath));

				// init values
				$path = '';
				$sharedPart = ltrim($parent['file_target'], '/');

				// rebuild path
				foreach ($targetPathSplit as $pathPart) {
					if ($pathPart !== $sharedPart) {
						$path = '/' . $pathPart . $path;
					} else {
						break;
					}
				}

				// prefix path with Shared
				$path = '/Shared' . $parent['file_target'] . $path;
			}

			// for group shares get a list of the group members
			if ($params['shareType'] === \OCP\Share::SHARE_TYPE_GROUP) {
				$userIds = \OC_Group::usersInGroup($params['shareWith']);
			} else {
				if ($params['shareType'] === \OCP\Share::SHARE_TYPE_LINK) {
					$userIds = array($util->getPublicShareKeyId());
				} else {
					$userIds = array($params['shareWith']);
				}
			}

			// get the path including mount point only if not a shared folder
			if(strncmp($path, '/Shared' , strlen('/Shared') !== 0)) {
				// get path including the the storage mount point
				$path = $util->getPathWithMountPoint($params['itemSource']);
			}

			// if we unshare a folder we need a list of all (sub-)files
			if ($params['itemType'] === 'folder') {
				$allFiles = $util->getAllFiles( $path );
			} else {
				$allFiles = array($path);
			}

			foreach ($allFiles as $path) {

				// check if the user still has access to the file, otherwise delete share key
				$sharingUsers = $util->getSharingUsersArray(true, $path);

				// Unshare every user who no longer has access to the file
				$delUsers = array_diff($userIds, $sharingUsers);

				// delete share key
				Keymanager::delShareKey($view, $delUsers, $path);
			}

		}
	}

	/**
	 * @brief after a file is renamed, rename its keyfile and share-keys also fix the file size and fix also the sharing
	 * @param array with oldpath and newpath
	 *
	 * This function is connected to the rename signal of OC_Filesystem and adjust the name and location
	 * of the stored versions along the actual file
	 */
	public static function postRename($params) {
		// Disable encryption proxy to prevent recursive calls
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$view = new \OC_FilesystemView('/');
		$session = new \OCA\Encryption\Session($view);
		$userId = \OCP\User::getUser();
		$util = new Util($view, $userId);

		// Format paths to be relative to user files dir
		$oldKeyfilePath = \OC\Files\Filesystem::normalizePath(
			$userId . '/' . 'files_encryption' . '/' . 'keyfiles' . '/' . $params['oldpath']);
		$newKeyfilePath = \OC\Files\Filesystem::normalizePath(
			$userId . '/' . 'files_encryption' . '/' . 'keyfiles' . '/' . $params['newpath']);

		// add key ext if this is not an folder
		if (!$view->is_dir($oldKeyfilePath)) {
			$oldKeyfilePath .= '.key';
			$newKeyfilePath .= '.key';

			// handle share-keys
			$localKeyPath = $view->getLocalFile($userId . '/files_encryption/share-keys/' . $params['oldpath']);
			$matches = glob(preg_quote($localKeyPath) . '*.shareKey');
			foreach ($matches as $src) {
				$dst = \OC\Files\Filesystem::normalizePath(str_replace($params['oldpath'], $params['newpath'], $src));

				// create destination folder if not exists
				if (!file_exists(dirname($dst))) {
					mkdir(dirname($dst), 0750, true);
				}

				rename($src, $dst);
			}

		} else {
			// handle share-keys folders
			$oldShareKeyfilePath = \OC\Files\Filesystem::normalizePath(
				$userId . '/' . 'files_encryption' . '/' . 'share-keys' . '/' . $params['oldpath']);
			$newShareKeyfilePath = \OC\Files\Filesystem::normalizePath(
				$userId . '/' . 'files_encryption' . '/' . 'share-keys' . '/' . $params['newpath']);

			// create destination folder if not exists
			if (!$view->file_exists(dirname($newShareKeyfilePath))) {
				$view->mkdir(dirname($newShareKeyfilePath), 0750, true);
			}

			$view->rename($oldShareKeyfilePath, $newShareKeyfilePath);
		}

		// Rename keyfile so it isn't orphaned
		if ($view->file_exists($oldKeyfilePath)) {

			// create destination folder if not exists
			if (!$view->file_exists(dirname($newKeyfilePath))) {
				$view->mkdir(dirname($newKeyfilePath), 0750, true);
			}

			$view->rename($oldKeyfilePath, $newKeyfilePath);
		}

		// build the path to the file
		$newPath = '/' . $userId . '/files' . $params['newpath'];
		$newPathRelative = $params['newpath'];

		if ($util->fixFileSize($newPath)) {
			// get sharing app state
			$sharingEnabled = \OCP\Share::isEnabled();

			// get users
			$usersSharing = $util->getSharingUsersArray($sharingEnabled, $newPathRelative);

			// update sharing-keys
			$util->setSharedFileKeyfiles($session, $usersSharing, $newPathRelative);
		}

		\OC_FileProxy::$enabled = $proxyStatus;
	}
}
