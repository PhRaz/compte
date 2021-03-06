<?php

namespace App\Controller;

use App\Bridge\AwsCognitoClient;
use App\Entity\User;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;


class SecurityController extends AbstractController
{
    /** @var AwsCognitoClient */
    var $cognitoClient;

    /** @var TranslatorInterface */
    var $translator;

    public function __construct(AwsCognitoClient $cognitoClient, TranslatorInterface $translator)
    {
        $this->cognitoClient = $cognitoClient;
        $this->translator = $translator;
    }

    /**
     * @route("/login", name="app_login")
     * @param AuthenticationUtils $authenticationUtils
     * @return Response
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        if ($error) {
            $this->addFlash(
                'danger',
                $this->translator->trans('Login ou mot de passe incorrect.')
            );
        }
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername]);
    }

    /**
     * @route("/signup", name="app_signup")
     * @param Request $request
     * @param AuthenticationUtils $authenticationUtils
     * @return Response
     * @throws \Exception
     */
    public function signup(Request $request): Response
    {
        $defaultData = [
            'email' => '',
            'password' => ''
        ];
        $form = $this->createFormBuilder($defaultData)
            ->add('email', EmailType::class)
            ->add('password', PasswordType::class, [
                'constraints' => [
                    new Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{8,}$/',
                        'htmlPattern' => '^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{8,}$'
                    ])
                ],
                'help' =>
                    $this->translator->trans('Le mot de passe doit comporter au moins 8 caractères avec au moins 1 chiffres, 1 majuscule et une minuscule.')
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->cognitoClient->signUp($data['email'], $data['password']);
            } catch (CognitoIdentityProviderException $e) {
                $this->addFlash('danger', $e->getAwsErrorMessage());
                return $this->render('security/signup.html.twig', ['form' => $form->createView()]);
            }

            $user = new User();
            $user->setDate(new \DateTime());
            $user->setMail($data['email']);
            $user->setName(explode('@', $data['email'])[0]);
            $manager = $this->getDoctrine()->getManager();
            $manager->persist($user);
            $manager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('Vous allez recevoir un mail qui contient un lien vous permettant de confirmer votre inscription. Vous pourrez ensuite vous connecter avec vos identifiants.')
            );
            return $this->redirectToRoute('app_login');
        }
        return $this->render('security/signup.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @route("/resetpassword", name="app_reset_password")
     * @param Request $request
     * @param AuthenticationUtils $authenticationUtils
     * @return Response
     */
    public function resetPassword(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $lastUsername = $authenticationUtils->getLastUsername();

        $defaultData = [
            'email' => $lastUsername
        ];
        $form = $this->createFormBuilder($defaultData)
            ->add('email', EmailType::class, [
                'label' => 'Email'
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                $this->cognitoClient->forgotPassword($data['email']);
            } catch (CognitoIdentityProviderException $e) {
                $message = $e->getAwsErrorMessage();
                if (is_null($message)) {
                    $message = $e->getMessage();
                }
                $this->addFlash('danger', $message);
                return $this->redirectToRoute('app_reset_password');
            }

            $this->addFlash(
                'success',
                $this->translator->trans('Vous allez recevoir un mail avec le code de confirmation à utiliser ici.')
            );
            return $this->redirectToRoute('app_reset_password_confirm');
        }
        return $this->render('security/reset.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @route("/resetpasswordConfirm", name="app_reset_password_confirm")
     * @param Request $request
     * @return Response
     */
    public function resetPasswordConfirm(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $lastUsername = $authenticationUtils->getLastUsername();

        $defaultData = [
            'email' => $lastUsername,
            'newPassword' => '',
            'code' => ''
        ];
        $form = $this->createFormBuilder($defaultData)
            ->add('email', EmailType::class, [
                'label' => $this->translator->trans('Email')
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => $this->translator->trans('Nouveau mot de passe')
            ])
            ->add('code', TextType::class, [
                'label' => $this->translator->trans('Code de confirmation')
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $this->cognitoClient->confirmForgotPassword($data['email'], $data['newPassword'], $data['code']);
            } catch (CognitoIdentityProviderException $e) {
                $message = $e->getAwsErrorMessage();
                if (is_null($message)) {
                    $message = $e->getMessage();
                }
                $this->addFlash('danger', $message);
                return $this->render('security/confirmReset.html.twig', ['form' => $form->createView()]);
            }
            return $this->redirectToRoute('app_login');
        }
        return $this->render('security/confirmReset.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @route("/logout", name="app_logout")
     * @return Response
     */
    public function logout(): Response
    {
        return $this->redirectToRoute('home');
    }
}
