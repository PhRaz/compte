<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use App\Bridge\AwsCognitoClient;

class UserProvider implements UserProviderInterface
{
    /**
     * @param AWSCognitoClient
     */
    private $cognitoClient;

    public function __construct(AWSCognitoClient $cognitoClient)
    {
        $this->cognitoClient = $cognitoClient;
    }

    /**
     * Symfony calls this method if you use features like switch_user
     * or remember_me.
     *
     * If you're not using these features, you do not need to implement
     * this method.
     *
     * @return UserInterface
     *
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByUsername($username)
    {
        $result = $this->cognitoClient->findByUsername($username);

        if (count($result['Users']) === 0) {
            throw new UsernameNotFoundException($username . " not found");
        }

        $user = new User();
        $user->setEmail($username);

        $groups = $this->cognitoClient->getRolesForUsername(
            $result['Users'][0]['Username']
        );

        if (count($groups['Groups']) > 0) {
            $user->setRoles(
                array_map(
                    function ($item) {
                        return 'ROLE_' . $item['GroupName'];
                    },
                    $groups['Groups']
                )
            );
        }

        return $user;
    }

    /**
     * Refreshes the user after being reloaded from the session.
     *
     * When a user is logged in, at the beginning of each request, the
     * User object is loaded from the session and then this method is
     * called. Your job is to make sure the user's data is still fresh by,
     * for example, re-querying for fresh User data.
     *
     * If your firewall is "stateless: true" (for a pure API), this
     * method is not called.
     *
     * @return UserInterface
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($user)));
        }

        return $this->loadUserByUsername($user->getEmail());
    }

    /**
     * Tells Symfony to use this provider for this User class.
     */
    public function supportsClass($class)
    {
        return User::class === $class;
    }
}
