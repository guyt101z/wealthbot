<?php
/**
 * Created by JetBrains PhpStorm.
 * User: amalyuhin
 * Date: 23.05.13
 * Time: 15:23
 * To change this template use File | Settings | File Templates.
 */

namespace Wealthbot\ClientBundle\Controller;


use Doctrine\ORM\EntityManager;
use Wealthbot\AdminBundle\Entity\CustodianMessage;
use Wealthbot\ClientBundle\ClientEvents;
use Wealthbot\ClientBundle\Docusign\TransferInformationConsolidatorCondition;
use Wealthbot\ClientBundle\Docusign\TransferInformationCustodianCondition;
use Wealthbot\ClientBundle\Docusign\TransferInformationPolicyCondition;
use Wealthbot\ClientBundle\Docusign\TransferInformationQuestionnaireCondition;
use Wealthbot\ClientBundle\Entity\AccountContribution;
use Wealthbot\ClientBundle\Entity\AccountGroup;
use Wealthbot\ClientBundle\Entity\Beneficiary;
use Wealthbot\ClientBundle\Entity\ClientAccount;
use Wealthbot\ClientBundle\Entity\RetirementPlanInformation;
use Wealthbot\ClientBundle\Entity\SystemAccount;
use Wealthbot\ClientBundle\Entity\TransferCustodianQuestionAnswer;
use Wealthbot\ClientBundle\Entity\TransferInformation;
use Wealthbot\ClientBundle\Entity\Workflow;
use Wealthbot\ClientBundle\Event\WorkflowEvent;
use Wealthbot\ClientBundle\Form\Handler\TransferBasicFormHandler;
use Wealthbot\ClientBundle\Form\Handler\TransferInformationFormHandler;
use Wealthbot\ClientBundle\Form\Handler\TransferPersonalFormHandler;
use Wealthbot\ClientBundle\Form\Type\AccountGroupsFormType;
use Wealthbot\ClientBundle\Form\Type\AccountOwnerReviewInformationFormType;
use Wealthbot\ClientBundle\Form\Type\BankInformationFormType;
use Wealthbot\ClientBundle\Form\Type\BeneficiariesCollectionFormType;
use Wealthbot\ClientBundle\Form\Type\RetirementPlanInfoFormType;
use Wealthbot\ClientBundle\Form\Type\TransferBasicFormType;
use Wealthbot\ClientBundle\Form\Type\TransferFundingDistributingFormType;
use Wealthbot\ClientBundle\Form\Type\TransferInformationFormType;
use Wealthbot\ClientBundle\Form\Type\AccountOwnerPersonalInformationFormType;
use Wealthbot\ClientBundle\Form\Type\TransferReviewFormType;
use Wealthbot\ClientBundle\Manager\SystemAccountManager;
use Wealthbot\ClientBundle\Model\AccountOwnerInterface;
use Wealthbot\ClientBundle\Repository\ClientAccountRepository;
use Wealthbot\RiaBundle\Entity\RiaCompanyInformation;
use Wealthbot\UserBundle\Entity\Document;
use Wealthbot\UserBundle\Entity\Profile;
use Wealthbot\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedException;

class BaseTransferController extends AclController
{
    public function accountAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $user = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $user->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $action = $account->getStepAction();
        $process = $account->getProcessStep();

        if (($action === ClientAccount::STEP_ACTION_TRANSFER || $action === ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING)
            && $account->hasGroup(AccountGroup::GROUP_FINANCIAL_INSTITUTION)
            && $process !== ClientAccount::PROCESS_STEP_FINISHED_APPLICATION
        ) {
            return $this->redirect($this->generateUrl($this->getRoutePrefix() . 'transfer_transfer_account', array('account_id' => $account->getId())));
        }

        if (!$action) {
            $isPreSaved = true;
            $isCurrentRetirement = $repo->findRetirementAccountById($account->getId()) ? true : false;

            if ($isCurrentRetirement) {
                $action = ClientAccount::STEP_ACTION_CREDENTIALS;
            } else {
                $action = ClientAccount::STEP_ACTION_BASIC;
            }
        } else {
            $isPreSaved = $account->getIsPreSaved();
        }

        if ($isPreSaved) {
            return $this->redirect($this->generateUrl($this->getRouteUrl($action), array('account_id' => $account->getId())));

        } elseif (
            (
                !$account->hasGroup(AccountGroup::GROUP_EMPLOYER_RETIREMENT)
                && $process === ClientAccount::PROCESS_STEP_FINISHED_APPLICATION
            ) || (
                $account->hasGroup(AccountGroup::GROUP_EMPLOYER_RETIREMENT)
                && $process === ClientAccount::PROCESS_STEP_COMPLETED_CREDENTIALS
            )
        ) {
            return $this->redirect($this->generateUrl('rx_client_transfer_applications'));
        }

        return $this->redirect($this->getRedirectUrl($account, $action));
    }

    public function progressMenuAction(ClientAccount $account, $step)
    {
        if ($step == ClientAccount::STEP_ACTION_ADDITIONAL_BASIC) {
            $step = ClientAccount::STEP_ACTION_BASIC;
        } else if ($step == ClientAccount::STEP_ACTION_ADDITIONAL_PERSONAL) {
            $step = ClientAccount::STEP_ACTION_PERSONAL;
        }

        $adm = $this->get('wealthbot_docusign.account_docusign.manager');
        $group = $account->getGroupName();
        $isRothOrIra = $account->isRothIraType();

        if ($group != AccountGroup::GROUP_EMPLOYER_RETIREMENT) {
            $items = array(
                'names' => array('Basics', 'Personal'),
                'steps' => array(ClientAccount::STEP_ACTION_BASIC, ClientAccount::STEP_ACTION_PERSONAL)
            );

            if ($account->hasGroup(AccountGroup::GROUP_OLD_EMPLOYER_RETIREMENT) || $isRothOrIra || $account->isTraditionalIraType()) {
                $items['names'][] = 'Beneficiaries';
                $items['steps'][] = ClientAccount::STEP_ACTION_BENEFICIARIES;
            }


            if ($account->hasGroup(AccountGroup::GROUP_FINANCIAL_INSTITUTION)) {
                $items['names'][] = 'Transfer Screen';
                $items['steps'][] = ClientAccount::STEP_ACTION_TRANSFER;
            }

            if ($account->hasGroup(AccountGroup::GROUP_OLD_EMPLOYER_RETIREMENT)) {
                $items['names'][] = 'Your Rollover';
                $items['steps'][] = ClientAccount::STEP_ACTION_ROLLOVER;
            }

            $hasFunding = $account->hasFunding();
            $hasDistributing = $account->hasDistributing();

            if ($hasFunding && $hasDistributing) {
                $items['names'][] = 'Funding & Distributing';
                $items['steps'][] = ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING;
            } elseif ($hasFunding || $account->hasGroup(AccountGroup::GROUP_DEPOSIT_MONEY)) {
                $items['names'][] = 'Funding';
                $items['steps'][] = ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING;
            } elseif ($hasDistributing) {
                $items['names'][] = 'Distributing';
                $items['steps'][] = ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING;
            } elseif ($adm->hasElectronicallySignError($account)) {
                $items['names'][] = 'Funding';
                $items['steps'][] = ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING;
            }

            $items['names'][] = 'Review & Sign';
            $items['steps'][] = ClientAccount::STEP_ACTION_REVIEW;

        } else {
            $items = array(
                'names' => array('Need Credentials'),
                'steps' => array(ClientAccount::STEP_ACTION_CREDENTIALS)
            );
        }

        return $this->render($this->getTemplate('progress_menu.html.twig'), array(
            'items'  => $items,
            'active' => array_search($step, $items['steps'])
        ));
    }

    public function basicAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        $primaryApplicant = $account->getPrimaryApplicant();
        $isPreSaved = $request->isXmlHttpRequest();

        $form = $this->createForm(new TransferBasicFormType($primaryApplicant), $primaryApplicant);
        $formHandler = new TransferBasicFormHandler($form, $request, $em);

        if ($request->isMethod('post')) {
            $process = $formHandler->process($account, $isPreSaved);
            if ($process) {
                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_BASIC);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('basic.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    public function additionalBasicAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        /** @var $client User */
        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array(
            'id' => $request->get('account_id'),
            'client_id' => $client->getId()
        ));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        if (!$repo->isJointAccount($account)) {
            throw new AccessDeniedException('Current account has not this step.');
        }

        $secondaryApplicant = $account->getSecondaryApplicant();
        if (!$secondaryApplicant) {
            throw $this->createNotFoundException('Account does not have secondary applicant.');
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $form = $this->createForm(new TransferBasicFormType($secondaryApplicant), $secondaryApplicant);
        $formHandler = new TransferBasicFormHandler($form, $request, $em);

        if ($request->isMethod('post')) {
            $process = $formHandler->process($account, $isPreSaved);

            if ($process) {
                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_ADDITIONAL_BASIC);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('additional_basic.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    public function personalAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array(
            'id' => $request->get('account_id'),
            'client_id' => $client->getId()
        ));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        $primaryApplicant = $account->getPrimaryApplicant();
        $isPreSaved = $request->isXmlHttpRequest();

        $isRollover = ($account->getGroupName() == AccountGroup::GROUP_OLD_EMPLOYER_RETIREMENT);
        $isRothOrIra = ($repo->isRothAccount($account) || $repo->isIraAccount($account));
        $withMaritalStatus = ($isRollover || $isRothOrIra);

        $form = $this->createForm(new AccountOwnerPersonalInformationFormType($primaryApplicant, $isPreSaved, $withMaritalStatus), $primaryApplicant);
        $formHandler = new TransferPersonalFormHandler($form, $request, $em, array('validator' => $this->get('validator')));

        if ($request->isMethod('post')) {
            $process = $formHandler->process($account, $withMaritalStatus);
            if ($process) {
                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_PERSONAL);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('personal.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    public function additionalPersonalAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        /** @var $client User */
        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array(
                'id' => $request->get('account_id'),
                'client_id' => $client->getId()
            ));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        if (!$repo->isJointAccount($account)) {
            throw new AccessDeniedException('Current account has not this step.');
        }

        $secondaryApplicant = $account->getSecondaryApplicant();
        if (!$secondaryApplicant) {
            throw $this->createNotFoundException('Account does not have secondary applicant.');
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $form = $this->createForm(new AccountOwnerPersonalInformationFormType($secondaryApplicant, $isPreSaved), $secondaryApplicant);
        $formHandler = new TransferPersonalFormHandler($form, $request, $em);

        if ($request->isMethod('post')) {
            $process = $formHandler->process($account);
            if ($process) {
                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_ADDITIONAL_PERSONAL);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('additional_personal.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    public function beneficiariesAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        /** @var $client User */
        $client = $this->getUser();
        $profile = $client->getProfile();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array(
                'id' => $request->get('account_id'),
                'client_id' => $client->getId()
            ));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        $beneficiaries = $account->getBeneficiaries();
        if (!$beneficiaries->count()) {
            if ($profile->getMaritalStatus() == Profile::CLIENT_MARITAL_STATUS_MARRIED) {
                $stepActionsKeys = array_flip(array_keys(ClientAccount::getStepActionChoices()));

                if ($stepActionsKeys[$account->getStepAction()] < $stepActionsKeys[ClientAccount::STEP_ACTION_BENEFICIARIES]) {
                    $beneficiary = $this->buildBeneficiaryByClient($client);
                }
            }

            if (!isset($beneficiary)) {
                $beneficiary = new Beneficiary();
            }

            $beneficiary->setAccount($account);
            $beneficiaries->add($beneficiary);
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $form = $this->createForm(new BeneficiariesCollectionFormType($isPreSaved));
        $form->get('beneficiaries')->setData($beneficiaries);

        $originalBeneficiaries = array();
        foreach ($beneficiaries as $item) {
            $originalBeneficiaries[] = $item;
        }

        if ($request->isMethod('post')) {
            $form->bind($request);

            if ($form->isValid()) {
                $beneficiaries = $form['beneficiaries']->getData();

                foreach ($beneficiaries as $beneficiary) {
                    $beneficiary->setAccount($account);
                    $em->persist($beneficiary);

                    foreach ($originalBeneficiaries as $key => $toDel) {
                        if ($beneficiary->getId() === $toDel->getId()) {
                            unset($originalBeneficiaries[$key]);
                        }
                    }
                }

                foreach ($originalBeneficiaries as $beneficiary) {
                    $account->removeBeneficiarie($beneficiary);

                    $em->remove($beneficiary);
                    $em->flush();
                }

                $account->setStepAction(ClientAccount::STEP_ACTION_BENEFICIARIES);
                $account->setIsPreSaved($isPreSaved);

                $em->persist($account);
                $em->flush();

                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_BENEFICIARIES);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('beneficiaries.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    public function fundingDistributingAction(Request $request)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $adm = $this->get('wealthbot_docusign.account_docusign.manager');
        $documentSignatureManager = $this->get('wealthbot_docusign.document_signature.manager');

        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');
        $custodianMessagesRepo = $em->getRepository('WealthbotAdminBundle:CustodianMessage');

        /** @var User $client */
        $client = $this->getUser();
        $riaCompanyInformation = $client->getRiaCompanyInformation();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        $transferFunding = $account->getAccountContribution();
        if (!$transferFunding) {
            $transferFunding = new AccountContribution();
            $transferFunding->setAccount($account);
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $formData = array('funding' => $transferFunding);
        $form = $this->createForm(new TransferFundingDistributingFormType($em, $account, $isPreSaved), $formData);
        $bankInfoForm = $this->createForm(new BankInformationFormType());

        if ($request->isMethod('post')) {
            $form->bind($request);

            if ($form->isValid()) {
                if ($account->hasFunding() ||
                    $account->hasGroup(AccountGroup::GROUP_DEPOSIT_MONEY) ||
                    $adm->hasElectronicallySignError($account)
                ) {
                    $transferFunding = $form->get('funding')->getData();
                    $em->persist($transferFunding);
                    $em->flush($transferFunding);
                } else {
                    $em->remove($transferFunding);
                }

                $account->setStepAction(ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING);
                $account->setIsPreSaved($isPreSaved);

                $em->persist($account);
                $em->flush();

                $em->refresh($account);

                $consolidatedAccounts = $account->getConsolidatedAccountsCollection();
                $bankTransferAccounts = $consolidatedAccounts->getBankTransferredAccounts();
                if ($bankTransferAccounts->count()) {
                    $accountContribution = $account->getAccountContribution();
                    if (!$documentSignatureManager->isDocumentSignatureForObjectExist($accountContribution)) {
                        $documentSignatureManager->createSignature($accountContribution);
                    }
                }

                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);

            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        $hasDocusignError = false;
        if ($account->getTransferInformation()) {
            $adm = $this->get('wealthbot_docusign.account_docusign.manager');

            $isAllowedNonElectronicallyTransfer = $riaCompanyInformation->getAllowNonElectronicallySigning();
            $hasDocusignError = (!$isAllowedNonElectronicallyTransfer && !$adm->isUsedDocusign($account->getId()));
        }

        return $this->render($this->getTemplate('funding_distributing.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'transfer_funding' => $transferFunding,
            'form' => $form->createView(),
            'bank_info_form' => $this->renderView($this->getTemplate('_create_bank_account_form.html.twig'), array(
                'form' => $bankInfoForm->createView(),
                'account_id' => $account->getId()
            )),
            'messages' => $custodianMessagesRepo->getAssocByCustodianId($riaCompanyInformation->getCustodianId()),
            'has_docusign_error' => $hasDocusignError
        ));
    }

    public function rolloverAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');
        $custodianMessagesRepo = $em->getRepository('WealthbotAdminBundle:CustodianMessage');

        $client = $this->getUser();
        /** @var RiaCompanyInformation $riaCompanyInformation */
        $riaCompanyInformation = $client->getRia()->getRiaCompanyInformation();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array(
            'id' => $request->get('account_id'),
            'client_id' => $client->getId()
        ));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        if ($account->getSystemType() !== SystemAccount::TYPE_ROTH_IRA &&
            !$account->hasGroup(AccountGroup::GROUP_OLD_EMPLOYER_RETIREMENT)
        ) {
            throw new AccessDeniedException('Current account has not this step.');
        }

        /** @var CustodianMessage $rolloverMessage */
        $rolloverMessage = $custodianMessagesRepo->findOneByCustodianIdAndType(
            $riaCompanyInformation->getCustodianId(),
            CustodianMessage::TYPE_ROLLOVER
        );

        if (!$this->get('session')->get('is_send_email', false)) {
            if ($rolloverMessage) {
                $this->get('wealthbot.mailer')->sendClientRolloverInstruction401Email($account, $rolloverMessage->getMessage());
                $this->get('session')->set('is_send_email', true);
            }
        }

        if ($request->isMethod('post')) {
            $account->setStepAction(ClientAccount::STEP_ACTION_ROLLOVER);
            $account->setIsPreSaved(false);

            $em->persist($account);
            $em->flush();

            $this->get('session')->remove('is_send_email');
            return $this->redirect($this->getRedirectUrl($account, ClientAccount::STEP_ACTION_ROLLOVER));
        }

        return $this->render($this->getTemplate('rollover.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'rollover_message' => $rolloverMessage
        ));
    }

    // TODO: Method needs refactoring. Move common code with the method reviewAction
    public function credentialsAction(Request $request)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $isCurrentRetirement = $repo->findRetirementAccountById($account->getId()) ? true : false;
        if (!$isCurrentRetirement) {
            throw new AccessDeniedException('Not current retirement accounts has not this step.');
        }

        $planInfo = $account->getRetirementPlanInfo();
        if (!$planInfo) {
            $planInfo = new RetirementPlanInformation();
            $planInfo->setAccount($account);
            $planInfo->setFinancialInstitution($account->getFinancialInstitution());
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $form = $this->createForm(new RetirementPlanInfoFormType(), $planInfo);

        if ($request->isMethod('post')) {
            $form->bind($request);

            if ($form->isValid()) {
                $planInfo = $form->getData();

                $account->setProcessStep(ClientAccount::PROCESS_STEP_COMPLETED_CREDENTIALS);
                $account->setStepAction(ClientAccount::STEP_ACTION_CREDENTIALS);
                $account->setIsPreSaved($isPreSaved);

                $em->persist($planInfo);
                $em->persist($account);
                $em->flush();

                $event = new WorkflowEvent($client, $account, Workflow::TYPE_ALERT);
                $this->get('event_dispatcher')->dispatch(ClientEvents::CLIENT_WORKFLOW, $event);

                // Create system account for client account
                /** @var $systemAccountManager SystemAccountManager */
                $systemAccountManager = $this->get('wealthbot_client.system_account_manager');
                $systemAccountManager->createSystemAccountForClientAccount($account);

                // If client complete all accounts
                $hasNotOpenedAccounts = $repo->findOneNotOpenedAccountByClientId($client->getId()) ? true : false;
                $profile = $client->getProfile();
                if (!$hasNotOpenedAccounts && ($profile->getRegistrationStep() != 7)) {
                    $profile->setRegistrationStep(7);
                    $profile->setClientStatus(Profile::CLIENT_STATUS_CLIENT);
                    $em->persist($profile);
                    $em->flush();
                }

                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_CREDENTIALS);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('credentials.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'form' => $form->createView()
        ));
    }

    // TODO: Method needs refactoring. Move common code with the method credentialsAction
    public function reviewAction(Request $request)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');
        $documentSignatureManager = $this->get('wealthbot_docusign.document_signature.manager');
        $documentManager = $this->get('wealthbot_user.document_manager');

        /** @var User $client */
        $client = $this->getUser();
        $custodian = $client->getCustodian();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        if (!$documentSignatureManager->isDocumentSignatureForObjectExist($account)) {
            $documentSignatureManager->createSignature($account);

            $signatures = $documentSignatureManager->getApplicationSignatures($account);
            $event = new WorkflowEvent($client, $account, Workflow::TYPE_PAPERWORK, $signatures);
            $this->get('event_dispatcher')->dispatch(ClientEvents::CLIENT_WORKFLOW, $event);
        }

        // Custodian disclosures links
        $custodianDisclosures = $documentManager->getCustodianDisclosuresLinks($custodian);
        if ($account->isTraditionalIraType()) {
            unset($custodianDisclosures[Document::TYPE_ROTH_ACCOUNT_DISCLOSURE]);
        } elseif ($account->isRothIraType()) {
            unset($custodianDisclosures[Document::TYPE_IRA_ACCOUNT_DISCLOSURE]);
        } else {
            unset($custodianDisclosures[Document::TYPE_ROTH_ACCOUNT_DISCLOSURE]);
            unset($custodianDisclosures[Document::TYPE_IRA_ACCOUNT_DISCLOSURE]);
        }

        $isCurrentRetirement = $repo->findRetirementAccountById($account->getId()) ? true : false;
        $form = $this->createForm(new TransferReviewFormType($documentSignatureManager, $account));
        $notSignedApplicationsError = null;

        $isPreSaved = $request->isXmlHttpRequest();
        if ($request->isMethod('post')) {
            $form->bind($request);

            if ($form->isValid()) {

                $account->setProcessStep(ClientAccount::PROCESS_STEP_FINISHED_APPLICATION);
                foreach ($account->getConsolidatedAccounts() as $consolidated) {
                    $consolidated->setProcessStep(ClientAccount::PROCESS_STEP_FINISHED_APPLICATION);
                }

                $account->setStepAction(ClientAccount::STEP_ACTION_REVIEW);
                $account->setIsPreSaved($isPreSaved);

                $em->persist($account);
                $em->flush($account);

                // Create system account for client account
                /** @var $systemAccountManager SystemAccountManager */
                $systemAccountManager = $this->get('wealthbot_client.system_account_manager');
                $systemAccountManager->createSystemAccountForClientAccount($account);

                // If client complete all accounts
                $hasNotOpenedAccounts = $repo->findOneNotOpenedAccountByClientId($client->getId()) ? true : false;
                $profile = $client->getProfile();
                if (!$hasNotOpenedAccounts && ($profile->getRegistrationStep() != 7)) {
                    $profile->setRegistrationStep(7);
                    $profile->setClientStatus(Profile::CLIENT_STATUS_CLIENT);

                    // Update client type
                    $clientSettings = $client->getClientSettings();
                    $clientSettings->setClientTypeCurrent();

                    $em->persist($profile);
                    $em->persist($clientSettings);
                    $em->flush();
                }

                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_REVIEW);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl));
                }

                return $this->redirect($redirectUrl);
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array('status' => 'error'));
            }
        }

        return $this->render($this->getTemplate('review.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'application_signatures' => $documentSignatureManager->findSignaturesByAccountConsolidatorId($account->getId()),
            'form' => $form->createView(),
            'is_current_retirement' => $isCurrentRetirement,
            'custodian' => $custodian,
            'custodian_disclosures' => $custodianDisclosures,
        ));
    }

    public function reviewOwnerInformationAction(Request $request, $owner_id)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $em = $this->get('doctrine.orm.entity_manager');
        $repository = $em->getRepository('WealthbotClientBundle:ClientAccountOwner');

        $accountOwner = $repository->find($owner_id);
        if (!$accountOwner) {
            return $this->getJsonResponse(array('status' => 'error', 'message' => 'Owner does not exist.'));
        }

        $owner = $accountOwner->getOwner();
        $form = $this->createForm(new AccountOwnerReviewInformationFormType($owner), $owner);

        $status = 'success';
        $content = $this->renderView('WealthbotClientBundle:Transfer:_review_owner_information_form.html.twig',
            array('form' => $form->createView(), 'owner' => $accountOwner)
        );

        if ($request->isMethod('post')) {
            $form->bind($request);

            if ($form->isValid()) {
                /** @var AccountOwnerInterface $data */
                $data = $form->getData();

                $em->persist($data->getObjectToSave());
                $em->flush();

                return $this->getJsonResponse(array('status' => 'success'));

            } else {
                $status = 'error';
                $content = $this->renderView('WealthbotClientBundle:Transfer:_review_owner_information_form.html.twig',
                    array('form' => $form->createView(), 'owner' => $accountOwner)
                );
            }
        }

        return $this->getJsonResponse(array('status' => $status, 'content' => $content));
    }


    public function transferAction(Request $request)
    {
        $adm = $this->get('wealthbot_docusign.account_docusign.manager');
        $documentSignatureManager = $this->get('wealthbot_docusign.document_signature.manager');
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $request->get('account_id'), 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException('You have not account with id: '.$request->get('account_id').'.');
        }

        $this->denyAccessForCurrentRetirementAccount($account);

        $accountIndex = $request->get('account_index', 1);
        $consolidatedAccounts = $account->getConsolidatedAccountsCollection();
        $consolidatedAccounts->first();
        $transferAccounts = $consolidatedAccounts->getTransferAccounts();

        if ($transferAccounts->isEmpty()) {
            $this->createNotFoundException('You have not transfer accounts.');
        }
        if (!$transferAccounts->containsKey($accountIndex)) {
            throw $this->createNotFoundException('Page not found.');
        }

        /** @var ClientAccount $currentAccount */
        $currentAccount = $transferAccounts->get($accountIndex);

        $information = $currentAccount->getTransferInformation();
        if (!$information) {
            $information = new TransferInformation();
            $information->setClientAccount($currentAccount);
        }

        $isPreSaved = $request->isXmlHttpRequest();
        $form = $this->createForm(new TransferInformationFormType($adm, $isPreSaved), $information);
        $formHandler = new TransferInformationFormHandler($form, $request, $em, array('client' => $client));

        if ($request->isMethod('post')) {
            if ($formHandler->process()) {
                $information = $form->getData();

                $account->setStepAction(ClientAccount::STEP_ACTION_TRANSFER);
                $account->setIsPreSaved($isPreSaved);

                $isDocusignAllowed = $adm->isDocusignAllowed($information, array(
                    new TransferInformationCustodianCondition(),
                    new TransferInformationPolicyCondition(),
                    new TransferInformationQuestionnaireCondition(),
                    new TransferInformationConsolidatorCondition()
                ));

                $adm->setIsUsedDocusign($account, $isDocusignAllowed);

                if (!$documentSignatureManager->isDocumentSignatureForObjectExist($information)) {
                    $documentSignatureManager->createSignature($information);
                }

                $redirectUrl = $this->getRedirectUrl($account, ClientAccount::STEP_ACTION_TRANSFER);

                if ($isPreSaved) {
                    return $this->getJsonResponse(array('status' => 'success', 'redirect_url' => $redirectUrl, 'route' => $this->getRouteUrl($this->get('wealthbot_client.transfer_screen_step.manager')->getNextStep($account, ClientAccount::STEP_ACTION_TRANSFER))));
                }

                // If account has next consolidated transfer account than redirect to it
                // else redirect to another step
                if ($transferAccounts->containsNextKey($accountIndex)) {
                    return $this->redirect(
                        $this->generateUrl($this->getRoutePrefix() . 'transfer_transfer_account', array(
                            'account_id' => $account->getId(),
                            'account_index' => ($accountIndex + 1)
                        ))
                    );

                } else {
                    return $this->redirect($redirectUrl);
                }
            } else if ($isPreSaved) {
                return $this->getJsonResponse(array(
                    'status' => 'error',
                    'form' => $this->renderView($this->getTemplate('_transfer_form.html.twig'), array(
                        'account' => $account,
                        'current_account' => $currentAccount,
                        'account_index' => $accountIndex,
                        'form' => $form->createView()
                    ))
                ));
            }
        }

        return $this->render($this->getTemplate('transfer.html.twig'), array(
            'client' => $client,
            'account' => $account,
            'transfer_accounts' => $transferAccounts,
            'current_account' => $currentAccount,
            'account_index' => $accountIndex,
            'information' => $information,
            'form' => $form->createView()
        ));
    }

    public function updateTransferFormAction(Request $request)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $adm = $this->get('wealthbot_docusign.account_docusign.manager');

        $account = $em->getRepository('WealthbotClientBundle:ClientAccount')->find($request->get('account_id'));
        if (!$account || $account->getClient() != $this->getUser()) {
            return $this->getJsonResponse(array('status' => 'error', 'message' => 'Account does not exist.'));
        }

        $accountIndex = $request->get('account_index', 1);
        $consolidatedAccounts = $account->getConsolidatedAccountsCollection();
        $transferAccounts = $consolidatedAccounts->getTransferAccounts();

        if ($transferAccounts->isEmpty()) {
            $this->createNotFoundException('You have not transfer accounts.');
        }
        if (!$transferAccounts->containsKey($accountIndex)) {
            throw $this->createNotFoundException('Page not found.');
        }

        $currentAccount = $transferAccounts->get($accountIndex);
        $transferInfo = $currentAccount->getTransferInformation();
        if (!$transferInfo) {
            $transferInfo = new TransferInformation();
            $transferInfo->setClientAccount($currentAccount);
        }

        if ($request->isMethod('post')) {
            $form = $this->createForm(new TransferInformationFormType($adm, true), $transferInfo);
            $form->bind($request);

            /** @var TransferInformation $transferInfo */
            $transferInfo = $form->getData();
            $transferInfo->setStatementDocument(null);

            // Remove answer if it value is null
            /** @var TransferCustodianQuestionAnswer $answer */
            foreach ($transferInfo->getQuestionnaireAnswers() as $answer) {
                if (null === $answer->getValue()) {
                    $transferInfo->removeQuestionnaireAnswer($answer);
                }
            }

            $isDocusignAllowed = $adm->isDocusignAllowed($transferInfo, array(
                new TransferInformationCustodianCondition(),
                new TransferInformationPolicyCondition(),
                new TransferInformationQuestionnaireCondition(),
                new TransferInformationConsolidatorCondition()
            ));

            $adm->setIsUsedDocusign($currentAccount, $isDocusignAllowed);

            $form = $this->createForm(new TransferInformationFormType($adm, true), $transferInfo);
            $formView = $form->createView();

            return $this->getJsonResponse(array(
                'status' => 'success',
                'custodian_questions_fields' => $this->renderView(
                    'WealthbotClientBundle:Transfer:_transfer_form_custodian_questions_fields.html.twig', array(
                        'form' => $formView
                    )
                ),
                'account_discrepancies_fields' => $this->renderView(
                    'WealthbotClientBundle:Transfer:_transfer_form_account_discrepancies_fields.html.twig', array(
                        'form' => $formView
                    )
                )
            ));
        }

        return $this->getJsonResponse(
            array('status' => 'error', 'message' => 'The operation failed due to some errors.')
        );
    }

    public function backAction($account_id, $action, $id = 0)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');
        $transferStepManager = $this->get('wealthbot_client.transfer_screen_step.manager');

        $client = $this->getUser();

        /** @var $account ClientAccount */
        $account = $repo->findOneBy(array('id' => $account_id, 'client_id' => $client->getId()));
        if (!$account) {
            $this->createNotFoundException(sprintf('You have not account with id: %s.', $account_id));
        }

        if ($id > 0) {
            $consolidatedAccount = $repo->findOneBy(array('id' => $id, 'consolidator_id' => $account->getId()));
        } else {
            $consolidatedAccount = null;
        }

        if ($consolidatedAccount && $account->getConsolidatedAccounts()) {
            $group = $consolidatedAccount->getGroupName();

            if ($group === AccountGroup::GROUP_FINANCIAL_INSTITUTION) {
                $transferAccounts = $account->getTransferConsolidatedAccounts();
                $key = $transferAccounts->indexOf($consolidatedAccount);

                if ($transferAccounts->containsKey($key-1)) {
                    return $this->redirect($this->generateUrl($this->getRoutePrefix() . 'transfer_transfer_account', array(
                        'account_id' => $account->getId(),
                        'account_index' => ($key)
                    )));
                }
            }
        }

        try {
            $route = $this->getRouteUrl($transferStepManager->getPreviousStep($account, $action));
        } catch (\Exception $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        if ($account->getConsolidatedAccounts()) {
            if ($route === ($this->getRoutePrefix() . 'transfer_transfer_account')) {
                $transferCount = $account->getTransferConsolidatedAccounts()->count();

                return $this->redirect($this->generateUrl($this->getRoutePrefix() . 'transfer_transfer_account', array(
                    'account_id' => $account->getId(),
                    'account_index' => $transferCount
                )));

            } elseif ($route === ($this->getRoutePrefix() . 'transfer_rollover')) {
                $rolloverCount = $account->getRolloverConsolidatedAccounts()->count();

                return $this->redirect($this->generateUrl($this->getRoutePrefix() . 'transfer_rollover', array(
                    'account_id' => $account->getId(),
                    'account_index' => $rolloverCount
                )));
            }
        }

        if ($route === ($this->getRoutePrefix() . 'transfer')) {
            return $this->redirect($this->generateUrl($route));
        }

        return $this->redirect($this->generateUrl($route, array('account_id' => $account->getId())));
    }

    public function finishedAction()
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository  */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $client = $this->getUser();
        $hasNotOpenedAccounts = $repo->findOneNotOpenedAccountByClientId($client->getId()) ? true : false;

        $riaCompanyInformation = $client->getRia()->getRiaCompanyInformation();

        $data = array('groups' => $this->get('session')->get('client.accounts.groups'));
        $this->get('session')->set('client.accounts.is_consolidate_account', false);

        $form = $this->createForm(new AccountGroupsFormType($client), $data);

        return $this->render($this->getTemplate('finished.html.twig'), array(
            'client' => $client,
            'form' => $form->createView(),
            'has_not_opened_accounts' => $hasNotOpenedAccounts,
            'ria_company_information' => $riaCompanyInformation
        ));
    }

    protected function getJsonResponse(array $data, $code = 200)
    {
        $response = json_encode($data);

        return new Response($response, $code, array('Content-Type'=>'application/json'));
    }

    /**
     * Get next step of the transfer account process
     *
     * @param \Wealthbot\ClientBundle\Entity\ClientAccount $account
     * @param string $action current step of the transfer account process
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function getRedirectUrl(ClientAccount $account, $action)
    {
        $transferStepManager = $this->get('wealthbot_client.transfer_screen_step.manager');
        $route = $this->getRouteUrl($transferStepManager->getNextStep($account, $action));

        if ($route == ($this->getRoutePrefix() . 'transfer_finished')) {
            return $this->generateUrl($route);
        }

        return $this->generateUrl($route, array('account_id' => $account->getId()));
    }

    private function buildBeneficiaryByClient(User $client)
    {
        $spouse = $client->getSpouse();
        $profile = $client->getProfile();

        $beneficiary = new Beneficiary();

        $beneficiary->setFirstName($spouse->getFirstName());
        $beneficiary->setMiddleName($spouse->getMiddleName());
        $beneficiary->setLastName($spouse->getLastName());
        $beneficiary->setBirthDate($spouse->getBirthDate());
        $beneficiary->setStreet($profile->getStreet());
        $beneficiary->setState($profile->getState());
        $beneficiary->setCity($profile->getCity());
        $beneficiary->setZip($profile->getZip());
        $beneficiary->setRelationship('Spouse');
        $beneficiary->setShare(100);

        return $beneficiary;
    }

    /**
     * Ger route for action
     *
     * @param string $action
     * @return string route
     * @throws \InvalidArgumentException
     */
    private function getRouteUrl($action)
    {
        switch ($action) {
            case '':
                $route = 'transfer';
                break;
            case ClientAccount::STEP_ACTION_BASIC:
                $route = 'transfer_basic';
                break;
            case ClientAccount::STEP_ACTION_ADDITIONAL_BASIC:
                $route = 'transfer_additional_basic';
                break;
            case ClientAccount::STEP_ACTION_PERSONAL:
                $route = 'transfer_personal';
                break;
            case ClientAccount::STEP_ACTION_ADDITIONAL_PERSONAL:
                $route = 'transfer_additional_personal';
                break;
            case ClientAccount::STEP_ACTION_BENEFICIARIES:
                $route = 'transfer_beneficiaries';
                break;
            case ClientAccount::STEP_ACTION_CREDENTIALS:
                $route = 'transfer_credentials';
                break;
            case ClientAccount::STEP_ACTION_FUNDING_DISTRIBUTING:
                $route = 'transfer_funding_distributing';
                break;
            case ClientAccount::STEP_ACTION_ROLLOVER:
                $route = 'transfer_rollover';
                break;
            case ClientAccount::STEP_ACTION_REVIEW:
                $route = 'transfer_review';
                break;
            case ClientAccount::STEP_ACTION_TRANSFER:
                $route = 'transfer_transfer_account';
                break;
            case ClientAccount::STEP_ACTION_FINISHED:
                $route = 'transfer_finished';
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Invalid value for action : %s.', $action));
                break;
        }

        return $this->getRoutePrefix() . $route;
    }

    protected function denyAccessForCurrentRetirementAccount(ClientAccount $account)
    {
        /** @var $em EntityManager */
        /** @var $repo ClientAccountRepository */
        $em = $this->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository('WealthbotClientBundle:ClientAccount');

        $isCurrentRetirement = $repo->findRetirementAccountById($account->getId()) ? true : false;
        if ($isCurrentRetirement) {
            throw new AccessDeniedException('Current retirement accounts has not this step.');
        }
    }

    /**
     * Get prefix for routing
     *
     * @return string
     */
    protected function getRoutePrefix()
    {
        return '';
    }

    /**
     * Get template
     *
     * @param $templateName
     * @return string
     */
    protected function getTemplate($templateName)
    {
        $params = array(
            'WealthbotClientBundle',
            $this->getViewsDir(),
            $templateName
        );

        return implode(':', $params);
    }

    /**
     * Returns true if array contains ClientAccount objects with sas cache property value more than 0
     * and false otherwise
     *
     * @param array $accounts array of ClientAccount objects
     * @return bool
     */
    protected function containsSasCash(array $accounts = array())
    {
        foreach ($accounts as $account) {
            if ($account->getSasCash() && $account->getSasCash() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns class name without 'Controller' substring
     *
     * @return string
     */
    private function getViewsDir()
    {
        $class = explode('\\', get_class($this));
        $class = end($class);

        return substr($class, 0, -strlen('Controller'));
    }
}