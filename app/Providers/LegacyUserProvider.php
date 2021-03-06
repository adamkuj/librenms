<?php
/**
 * LegacyUserProvider.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\User;
use DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use LibreNMS\Authentication\LegacyAuth;
use LibreNMS\Exceptions\AuthenticationException;
use Log;
use Request;
use Session;
use Toastr;

class LegacyUserProvider implements UserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param  mixed $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier)
    {
        return User::find($identifier);
    }

    /**
     * Retrieve a user by their legacy auth specific identifier.
     *
     * @param  int $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByLegacyId($identifier)
    {
        error_reporting(0);
        $legacy_user = LegacyAuth::get()->getUser($identifier);
        error_reporting(-1);

        return $this->fetchUserByName($legacy_user['username']);
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param  mixed $identifier
     * @param  string $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByToken($identifier, $token)
    {
        $user = new User();
        $user = $user->where($user->getAuthIdentifierName(), $identifier)->first();

        if (!$user) {
            return null;
        }

        $rememberToken = $user->getRememberToken();
        if ($rememberToken && hash_equals($rememberToken, $token)) {
            if (LegacyAuth::get()->userExists($user->username)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable $user
     * @param  string $token
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, $token)
    {
        $user->setRememberToken($token);
        $timestamps = $user->timestamps;
        $user->timestamps = false;
        $user->save();
        $user->timestamps = $timestamps;
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param  array $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials)
    {
        return $this->fetchUserByName($credentials['username'], $credentials['password']);
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable $user
     * @param  array $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        error_reporting(0);

        $authorizer = LegacyAuth::get();

        try {
            // try authentication methods
            // collect username and password
            $password = null;
            if (isset($credentials['username']) && isset($credentials['password'])) {
                $username = $credentials['username'];
                $password = $credentials['password'];
            } elseif ($authorizer->authIsExternal()) {
                $username = $authorizer->getExternalUsername();
            }

            if (!isset($username) || !$authorizer->authenticate($username, $password)) {
                throw new AuthenticationException('Invalid Credentials');
            }

            return true;
        } catch (AuthenticationException $ae) {
            global $debug;

            $auth_message = $ae->getMessage();
            if ($debug) {
                $auth_message .= '<br /> ' . $ae->getFile() . ': ' . $ae->getLine();
            }
            \Toastr::error($auth_message);

            if (empty($username)) {
                $username = Session::get('username', $credentials['username']);
            }
            DB::table('authlog')->insert(['user' => $username, 'address' => Request::ip(), 'result' => $auth_message]);
        } finally {
            error_reporting(-1);
        }

        return false;
    }

    /**
     * Fetch user by username from legacy auth, update it or add it to the db then return it.
     *
     * @param string $username
     * @param string $password
     * @return User|null
     */
    protected function fetchUserByName($username, $password = null)
    {
        error_reporting(0);

        $auth = LegacyAuth::get();
        $type = LegacyAuth::getType();

        // ldap based auth we should bind before using, otherwise searches may fail due to anonymous bind
        if (method_exists($auth, 'bind')) {
            $auth->bind($username, $password);
        }

        $auth_id = $auth->getUserid($username);
        $new_user = $auth->getUser($auth_id);

        error_reporting(-1);

        if (empty($new_user)) {
            // some legacy auth create users in the authenticate method, if it doesn't exist yet, lets try authenticate (Laravel calls retrieveByCredentials first)
            try {
                error_reporting(0);

                $auth->authenticate($username, $password);
                $auth_id = $auth->getUserid($username);
                $new_user = $auth->getUser($auth_id);

                error_reporting(-1);
            } catch (AuthenticationException $ae) {
                Toastr::error($ae->getMessage());
            }

            if (empty($new_user)) {
                Log::error("Auth Error ($type): No user ($auth_id) [$username]");
                return null;
            }
        }

        unset($new_user['user_id']);

        // remove null fields
        $new_user = array_filter($new_user, function ($var) {
            return !is_null($var);
        });

        // always create an entry in the users table, but separate by type
        $user = User::thisAuth()->firstOrNew(['username' => $username], $new_user);
        /** @var User $user */


        $user->fill($new_user); // fill all attributes
        $user->auth_type = $type; // doing this here in case it was null (legacy)
        $user->auth_id = $auth_id;

	// ALK 20181102 commenting out since this breaks http-auth guest access per https://community.librenms.org/t/duplicate-entry-http-auth-admin-for-key-username/5611
        //$user->save();

        return $user;
    }
}
