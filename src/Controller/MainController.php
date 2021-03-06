<?php

namespace App\Controller;

use App\Bridge\AwsCognitoClient;
use App\Entity\Event;
use App\Entity\Expense;
use App\Entity\Operation;
use App\Entity\User;
use App\Entity\UserEvent;
use App\Form\OperationType;
use App\Form\EventType;
use App\Repository\OperationRepository;
use App\Repository\UserRepository;
use App\Repository\EventRepository;
use App\Service\EventHelper;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class MainController
 * @package App\Controller
 */
class MainController extends AbstractController
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
     * @route("/{_locale}/home", name="home", requirements={"_locale"="%app.locales%"})
     * @param $request
     * @param $freeLimit
     * @return Response
     * @throws \Exception
     */
    public function home(Request $request, $freeLimit): Response
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);
        $nbUser = $userRepo->getNbUser();

        /** @var EventRepository $eventRepo */
        $eventRepo = $this->getDoctrine()->getRepository(Event::class);
        $nbEvent = $eventRepo->getNbEvent();

        /** @var OperationRepository $operation */
        $operation = $this->getDoctrine()->getRepository(Operation::class);
        $nbOperation = $operation->getNbOperation();

        return $this->render("home-" . $request->getLocale() . ".html.twig", [
            'nbUser' => $nbUser,
            'nbEvent' => $nbEvent,
            'nbOperation' => $nbOperation,
            'freeLimit' => $freeLimit
        ]);
    }

    /**
     * @route("/{_locale}/event/list", name="event_list", requirements={"_locale"="%app.locales%"})
     * @param $authChecker
     * @param $freeLimit array
     * @return Response
     * @throws \Exception
     */
    public function eventList(AuthorizationCheckerInterface $authChecker, $freeLimit): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $loggedUser */
        $loggedUser = $this->getUser();
        $mail = $loggedUser->getEmail();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => $mail]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var UserRepository $userRepo */
        $userEventRepo = $this->getDoctrine()->getRepository(UserEvent::class);

        /*
         * check user limit on number of event
         */
        if ($authChecker->isGranted('ROLE_PREMIUM')) {
            $newEventButton = ($userEventRepo->getUserNbEvent($user) < $freeLimit['maxNbEvent']['premium']);
        } else {
            $newEventButton = ($userEventRepo->getUserNbEvent($user) < $freeLimit['maxNbEvent']['free']);
        }

        $userId = $user->getId();
        $data = $userRepo->getUserEvents($userId);

        $balance = [];
        foreach ($data->getUserEvents() as $userEvent) {
            $event = $userEvent->getEvent();
            $eventId = $event->getId();
            /** @var EventHelper $eventHelper */
            $eventHelper = new EventHelper($event);
            foreach ($eventHelper->summary as $userId => $userData) {
                $balance[$eventId][$userId] = $userData['balance'];
            }
        }

        return $this->render("eventList.html.twig", [
            'user' => $data,
            'newEventButton' => $newEventButton,
            'balance' => $balance
        ]);
    }

    /**
     * @route("/{_locale}/event/create", name="event_create", requirements={"_locale"="%app.locales%"})
     * @param $request Request
     * @param $freeLimit
     * @param $maxNbParticipant
     * @return Response
     * @throws \Exception
     */
    public function eventCreate(Request $request, AuthorizationCheckerInterface $authChecker, $freeLimit)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $loggedUser */
        $loggedUser = $this->getUser();
        $mail = $loggedUser->getEmail();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => $mail]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var UserRepository $userRepo */
        $userEventRepo = $this->getDoctrine()->getRepository(UserEvent::class);
        $nbEvent = $userEventRepo->getUserNbEvent($user);
        if ($nbEvent >= $freeLimit['maxNbEvent']['premium']) {
            throw new AccessDeniedException();
        }
        if ($nbEvent >= $freeLimit['maxNbEvent']['free']) {
            $this->denyAccessUnlessGranted('ROLE_PREMIUM');
        }
        if ($authChecker->isGranted('ROLE_PREMIUM')) {
            $maxNbParticipant = $freeLimit['maxNbParticipant']['premium'];
        } else {
            $maxNbParticipant = $freeLimit['maxNbParticipant']['free'];
        }

        $event = new Event();

        /*
         * admin is a predefined user on the created event
         */
        $userEvent = new UserEvent();
        $userEvent->setAdministrator(true);
        $user->addUserEvent($userEvent);
        $event->setDate(new \DateTime());
        $event->addUserEvent($userEvent);

        $form = $this->createForm(eventType::class, $event);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            foreach ($event->getUserEvents() as $userEvent) {
                $userEvent
                    ->setDate(new \DateTime())
                    ->setEvent($event);
                if ($userEvent->getAdministrator() === null) {
                    $userEvent->setAdministrator(false);
                }

                /** @var User $user */
                $user = $userEvent->getUser();
                /** @var User $userCheck */
                $userCheck = $userRepo->findOneBy(['mail' => $user->getMail()]);
                if (is_null($userCheck)) {
                    /*
                     * it is a new user
                     */
                    $user->setDate(new \DateTime());
                    $user->setName(explode('@', $user->getMail())[0]);
                    $entityManager->persist($user);
                } else {
                    /*
                     * the user exists already, link to this new event
                     */
                    $userCheck->addUserEvent($userEvent);
                }
                $entityManager->persist($userEvent);

                /*
                 * TODO manage async operation on user creation
                 */
                try {
                    $this->cognitoClient->adminCreateUser($user->getMail());
                } catch (CognitoIdentityProviderException $e) {
                    if ($e->getAwsErrorCode() == 'UsernameExistsException') {
                        /*
                         * TODO send a mail for information to the user (if admin / if user)
                         */
                    } else {
                        $this->addFlash('danger', $e->getAwsErrorMessage() . " (" . $user->getMail() . ")");
                    }
                }
            }

            $entityManager->persist($event);
            $entityManager->flush();

            return $this->redirectToRoute('event_list');
        }

        return $this->render('eventCreate.html.twig', [
            'form' => $form->createView(),
            'maxNbParticipant' => $maxNbParticipant
        ]);
    }

    /**
     * @route("/{_locale}/event/update/{eventId}", name="event_update", requirements={"_locale"="%app.locales%"})
     * @param Request $request
     * @param $authChecker
     * @param $eventId
     * @param $freeLimit
     * @return Response
     * @throws \Exception
     */
    public function eventUpdate(Request $request, AuthorizationCheckerInterface $authChecker, $eventId, $freeLimit)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $loggedUser */
        $loggedUser = $this->getUser();
        $mail = $loggedUser->getEmail();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => $mail]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var EventRepository $eventRepo */
        $eventRepo = $this->getDoctrine()->getRepository(Event::class);

        /** @var Event $event */
        $event = $eventRepo->getEventOperations($eventId);

        if (!$event->isUserAdmin($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        if ($authChecker->isGranted('ROLE_PREMIUM')) {
            $maxNbParticipant = $freeLimit['maxNbParticipant']['premium'];
        } else {
            $maxNbParticipant = $freeLimit['maxNbParticipant']['free'];
        }

        $form = $this->createForm(eventType::class, $event);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            /** @var UserRepository $userRepo */
            $userRepo = $this->getDoctrine()->getRepository(User::class);

            foreach ($event->getUserEvents() as $userEvent) {

                /** @var User $user */
                $user = $userEvent->getUser();

                /*
                 * check if new user added to event
                 */
                if (!$entityManager->contains($userEvent)) {

                    $userEvent
                        ->setDate(new \DateTime())
                        ->setEvent($event);
                    if ($userEvent->getAdministrator() === null) {
                        $userEvent->setAdministrator(false);
                    }

                    /** @var User $userCheck */
                    $userCheck = $userRepo->findOneBy(['mail' => $user->getMail()]);
                    if (is_null($userCheck)) {
                        /*
                         * it is a new user
                         */
                        $user->setDate(new \DateTime());
                        $user->setName(explode('@', $user->getMail())[0]);
                        $entityManager->persist($user);
                    } else {
                        /*
                         * the user exists already, link to this new event
                         */
                        $userCheck->addUserEvent($userEvent);
                    }
                    $entityManager->persist($userEvent);
                }

                /*
                 * TODO manage async operation on user creation
                 */
                try {
                    /*
                     * systematically create user, cognito manages already existing user
                     */
                    $this->cognitoClient->adminCreateUser($user->getMail());
                } catch (CognitoIdentityProviderException $e) {
                    if ($e->getAwsErrorCode() == 'UsernameExistsException') {
                        /*
                         * TODO send a mail for information to the user (if admin / if user)
                         */
                    } else {
                        $this->addFlash('danger', $e->getAwsErrorMessage() . " (" . $user->getMail() . ")");
                    }
                }
            }

            $entityManager->flush();
            return $this->redirectToRoute('event_list');
        }

        return $this->render('eventUpdate.html.twig', [
            'form' => $form->createView(),
            'maxNbParticipant' => $maxNbParticipant
        ]);
    }

    /**
     * @route("/{_locale}/operation/list/{eventId}", name="operation_list", requirements={"_locale"="%app.locales%"})
     * @param $authChecker
     * @param integer $eventId
     * @param $freeLimit
     * @return Response
     * @throws \Exception
     */
    public function operationList(AuthorizationCheckerInterface $authChecker, $eventId, $freeLimit)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $user */
        $user = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var EventRepository $eventRepo */
        $eventRepo = $this->getDoctrine()->getRepository(Event::class);

        /** @var Event $event */
        $event = $eventRepo->getEventOperations($eventId);

        if (!$event->isUserParticipant($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        if (count($event->getOperations()) > 0) {
            $event = $eventRepo->getEventOperations($eventId, true);
        }
        $eventHelper = new EventHelper($event);

        /*
         * check user limit on number of operation
         */
        if ($authChecker->isGranted('ROLE_PREMIUM')) {
            $newOperationButton = (count($eventHelper->operations) < $freeLimit['maxNbOperation']['premium']);
        } else {
            $newOperationButton = (count($eventHelper->operations) < $freeLimit['maxNbOperation']['free']);
        }

        return $this->render('operationList.html.twig', [
            'user' => $user,
            'event' => $eventHelper,
            'newOperationButton' => $newOperationButton
        ]);
    }

    /**
     * @Route("/{_locale}/operation/create/{eventId}", name="operation_create", requirements={"_locale"="%app.locales%"})
     * @param Request $request
     * @param $eventId
     * @return Response
     * @throws \Exception
     */
    public function operationCreate(Request $request, $eventId): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $loggedUser */
        $loggedUser = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var array $users */
        $users = $userRepo->getEventUsers($eventId);

        /** @var Operation $operation */
        $operation = new Operation();

        /** @var UserEvent $userEvent */
        $userEvent = $users[0]->getUserEvents()[0];

        /** @var Event $event */
        $event = $userEvent->getEvent();

        if (!$event->isUserParticipant($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /*
         * init operation and relate to the event
         */
        $operation->setUser($loggedUser);
        $operation->setDate(new \DateTime());
        $event->addOperation($operation);

        foreach ($users as $user) {
            /*
             * for each user attach an expense to the operation,
             * users are ordered on pseudo field
             */
            $expense = new Expense();
            $expense->setUser($user);
            $expense->setExpense(0);
            $expense->setPayment(1);
            $operation->addExpense($expense);
        }

        $form = $this->createForm(OperationType::class, $operation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($operation);
            $entityManager->flush();

            return $this->redirectToRoute('operation_list', ['eventId' => $eventId]);
        }

        return $this->render("operationCreate.html.twig", ['form' => $form->createView(), 'eventId' => $event->getId()]);
    }

    /**
     * @route("/{_locale}/operation/update/{operationId}", name="operation_update", requirements={"_locale"="%app.locales%"})
     * @param Request $request
     * @param integer $operationId
     * @return Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function operationUpdate(Request $request, $operationId)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var OperationRepository $operationRepo */
        $operationRepo = $this->getDoctrine()->getRepository(Operation::class);

        /** @var Operation $operation */
        $operation = $operationRepo->findForUpdate($operationId);

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $loggedUser */
        $loggedUser = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        if (!$operation->getEvent()->isUserParticipant($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        $form = $this->createForm(OperationType::class, $operation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->flush();

            $eventId = $operation->getEvent()->getId();

            return $this->redirectToRoute('operation_list', ['eventId' => $eventId]);
        }

        return $this->render("operationUpdate.html.twig", ['form' => $form->createView(), 'operation' => $operation]);
    }

    /**
     * @route("/{_locale}/operation/remove/{operationId}", name="operation_remove", requirements={"_locale"="%app.locales%"})
     * @param Request $request
     * @param integer $operationId
     * @return Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function operationRemove(Request $request, $operationId)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var OperationRepository $operationRepo */
        $operationRepo = $this->getDoctrine()->getRepository(Operation::class);

        /** @var Operation $operation */
        $operation = $operationRepo->findForUpdate($operationId);

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $loggedUser */
        $loggedUser = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        if (!$operation->getEvent()->isUserParticipant($loggedUser)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            if ($operation !== null) {
                $eventId = $operation->getEvent()->getId();

                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->remove($operation);
                $entityManager->flush();
                return $this->redirectToRoute('operation_list', ['eventId' => $eventId]);
            } else {
                return $this->redirectToRoute('event_list');
            }

        }

        return $this->render("operationRemove.html.twig", ['form' => $form->createView(), 'operation' => $operation]);
    }

    /**
     * @route("/{_locale}/user/summary/{eventId}", name="user_summary", requirements={"_locale"="%app.locales%"})
     * @param integer $eventId
     * @return Response
     * @throws \Exception
     */
    public function userSummary($eventId)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $user */
        $user = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var EventRepository $eventRepo */
        $eventRepo = $this->getDoctrine()->getRepository(Event::class);

        /** @var Event $event */
        $event = $eventRepo->getEventOperations($eventId);

        if (!$event->isUserParticipant($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        if (count($event->getOperations()) === 0) {
            return $this->redirectToRoute('operation_list', ['eventId' => $eventId]);
        }

        $event = $eventRepo->getEventOperations($eventId, true);
        $eventHelper = new EventHelper($event);

        return $this->render('userSummary.html.twig', [
            'user' => $user,
            'event' => $eventHelper,
        ]);
    }

    /**
     * @route("/{_locale}/cgu", name="cgu", requirements={"_locale"="%app.locales%"})
     * @return Response
     */
    public function cgu()
    {
        return $this->render('cgu.html.twig');
    }

    /**
     * @route("/{_locale}/user/summary/mail/{eventId}", name="user_summary_mail", requirements={"_locale"="%app.locales%"})
     * @param Request $request
     * @param integer $eventId
     * @param \Swift_Mailer $mailer
     * @return Response
     * @throws \Exception
     */
    public function userSummaryMail(Request $request, $eventId, \Swift_Mailer $mailer)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Security\User $authenticatedUser */
        $authenticatedUser = $this->getUser();

        /** @var UserRepository $userRepo */
        $userRepo = $this->getDoctrine()->getRepository(User::class);

        /** @var User $user */
        $user = $userRepo->findOneBy(['mail' => $authenticatedUser->getEmail()]);
        if (is_null($user)) {
            /*
             * should never occurs
             */
            return ($this->redirectToRoute("app_logout"));
        }

        /** @var EventRepository $eventRepo */
        $eventRepo = $this->getDoctrine()->getRepository(Event::class);

        /** @var Event $event */
        $event = $eventRepo->getEventOperations($eventId);

        /*
         * security check on user participating to the event
         */
        if (!$event->isUserParticipant($user)) {
            throw $this->createAccessDeniedException();
        }

        if (count($event->getOperations()) === 0) {
            throw new \Exception("no operation found");
        }

        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $event = $eventRepo->getEventOperations($eventId, true);
            $eventHelper = new EventHelper($event);

            $message = new \Swift_Message('[dichipot] résumé ' . $event->getName());
            $logo = $message->embed(new \Swift_Image('build/dichipot_logo_height_64.png'));

            $mailResponse = $this->render('mail/userSummary.html.twig', [
                'user' => $user,
                'event' => $eventHelper,
                'logo' => $logo
            ]);

            $message->setFrom('admin@dichipot.com')
                ->setTo($user->getMail())
                ->setBody(
                    $mailResponse,
                    'text/html'
                );

            $nbMail = $mailer->send($message);

            if ($nbMail == 1) {
                $this->addFlash('success',
                    $this->translator->trans("Un résume des dépenses vous a été envoyé par mail à ") . $user->getMail() . ".");
            } else {
                $this->addFlash('notice',
                    $this->translator->trans("Une erreur a empéché l'envoi d'un mail à ") . $user->getMail() . ".");
            }

            return $this->redirectToRoute('user_summary', ['eventId' => $eventId]);
        }

        return $this->render("userSendMail.html.twig", ['form' => $form->createView(), 'user' => $user, 'event' => $event]);
    }
}
