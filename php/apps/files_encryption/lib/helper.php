<?php

/**
 * ownCloud
 *
 * @author Florin Peter
 * @copyright 2013 Florin Peter <owncloud@florin-peter.de>
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

/**
 * @brief Class to manage registration of hooks an various helper methods
 * @package OCA\Encryption
 */
class Helper {

	/**
	 * @brief register share related hooks
	 *
	 */
	public static function registerShareHooks() {

		\OCP\Util::connectHook('OCP\Share', 'pre_shared', 'OCA\Encryption\Hooks', 'preShared');
		\OCP\Util::connectHook('OCP\Share', 'post_shared', 'OCA\Encryption\Hooks', 'postShared');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', 'OCA\Encryption\Hooks', 'postUnshare');
	}

	/**
	 * @brief register user related hooks
	 *
	 */
	public static function registerUserHooks() {

		\OCP\Util::connectHook('OC_User', 'post_login', 'OCA\Encryption\Hooks', 'login');
		\OCP\Util::connectHook('OC_User', 'post_setPassword', 'OCA\Encryption\Hooks', 'setPassphrase');
		\OCP\Util::connectHook('OC_User', 'post_createUser', 'OCA\Encryption\Hooks', 'postCreateUser');
		\OCP\Util::connectHook('OC_User', 'post_deleteUser', 'OCA\Encryption\Hooks', 'postDeleteUser');
	}

	/**
	 * @brief register filesystem related hooks
	 *
	 */
	public static function registerFilesystemHooks() {

		\OCP\Util::connectHook('OC_Filesystem', 'post_rename', 'OCA\Encryption\Hooks', 'postRename');
	}

	/**
	 * @brief setup user for files_encryption
	 *
	 * @param Util $util
	 * @param string $password
	 * @return bool
	 */
	public static function setupUser($util, $password) {
		// Check files_encryption infrastructure is ready for action
		if (!$util->ready()) {

			\OCP\Util::writeLog('Encryption library', 'User account "' . $util->getUserId()
												 . '" is not ready for encryption; configuration started', \OCP\Util::DEBUG);

			if (!$util->setupServerSide($password)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @brief enable recovery
	 *
	 * @param $recoveryKeyId
	 * @param $recoveryPassword
	 * @internal param \OCA\Encryption\Util $util
	 * @internal param string $password
	 * @return bool
	 */
	public static function adminEnableRecovery($recoveryKeyId, $recoveryPassword) {
		$view = new \OC\Files\View('/');

		if ($recoveryKeyId === null) {
			$recoveryKeyId = 'recovery_' . substr(md5(time()), 0, 8);
			\OC_Appconfig::setValue('files_encryption', 'recoveryKeyId', $recoveryKeyId);
		}

		if (!$view->is_dir('/owncloud_private_key')) {
			$view->mkdir('/owncloud_private_key');
		}

		if (
			(!$view->file_exists("/public-keys/" . $recoveryKeyId . ".public.key")
			 || !$view->file_exists("/owncloud_private_key/" . $recoveryKeyId . ".private.key"))
		) {

			$keypair = \OCA\Encryption\Crypt::createKeypair();

			\OC_FileProxy::$enabled = false;

			// Save public key

			if (!$view->is_dir('/public-keys')) {
				$view->mkdir('/public-keys');
			}

			$view->file_put_contents('/public-keys/' . $recoveryKeyId . '.public.key', $keypair['publicKey']);

			// Encrypt private key empthy passphrase
			$encryptedPrivateKey = \OCA\Encryption\Crypt::symmetricEncryptFileContent($keypair['privateKey'], $recoveryPassword);

			// Save private key
			$view->file_put_contents('/owncloud_private_key/' . $recoveryKeyId . '.private.key', $encryptedPrivateKey);

			// create control file which let us check later on if the entered password was correct.
			$encryptedControlData = \OCA\Encryption\Crypt::keyEncrypt("ownCloud", $keypair['publicKey']);
			if (!$view->is_dir('/control-file')) {
				$view->mkdir('/control-file');
			}
			$view->file_put_contents('/control-file/controlfile.enc', $encryptedControlData);

			\OC_FileProxy::$enabled = true;

			// Set recoveryAdmin as enabled
			\OC_Appconfig::setValue('files_encryption', 'recoveryAdminEnabled', 1);

			$return = true;

		} else { // get recovery key and check the password
			$util = new \OCA\Encryption\Util(new \OC_FilesystemView('/'), \OCP\User::getUser());
			$return = $util->checkRecoveryPassword($recoveryPassword);
			if ($return) {
				\OC_Appconfig::setValue('files_encryption', 'recoveryAdminEnabled', 1);
			}
		}

		return $return;
	}


	/**
	 * @brief disable recovery
	 *
	 * @param $recoveryPassword
	 * @return bool
	 */
	public static function adminDisableRecovery($recoveryPassword) {
		$util = new Util(new \OC_FilesystemView('/'), \OCP\User::getUser());
		$return = $util->checkRecoveryPassword($recoveryPassword);

		if ($return) {
			// Set recoveryAdmin as disabled
			\OC_Appconfig::setValue('files_encryption', 'recoveryAdminEnabled', 0);
		}

		return $return;
	}


	/**
	 * @brief checks if access is public/anonymous user
	 * @return bool
	 */
	public static function isPublicAccess() {
		if (\OCP\USER::getUser() === false
			|| (isset($_GET['service']) && $_GET['service'] == 'files'
				&& isset($_GET['t']))
		) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @brief Format a path to be relative to the /user/files/ directory
	 * @param string $path the absolute path
	 * @return string e.g. turns '/admin/files/test.txt' into 'test.txt'
	 */
	public static function stripUserFilesPath($path) {
		$trimmed = ltrim($path, '/');
		$split = explode('/', $trimmed);
		$sliced = array_slice($split, 2);
		$relPath = implode('/', $sliced);

		return $relPath;
	}
}