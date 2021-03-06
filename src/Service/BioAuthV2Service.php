<?php
/**
 * Created by PhpStorm.
 * User: Joe Alamo
 * Date: 10/02/2016
 * Time: 17:25
 */

namespace BiometricSite\Service;


use BiometricSite\Controller\BioAuthV2ControllerInterface;
use BiometricSite\Model\BiometricClient;
use BiometricSite\Model\BiometricSession;
use BiometricSite\Repository\BioAuthSessionRepositoryInterface;
use BiometricSite\Repository\BioClientRepositoryInterface;
use BiometricSite\Repository\BioSessionRepositoryInterface;
use BiometricSite\Repository\PrevClientRandomRepositoryInterface;

class BioAuthV2Service extends AbstractBioAuthService implements BioAuthV2ServiceInterface {
    private $prevClientRandomRepository;

    public function __construct(
        BioClientRepositoryInterface $bioClientRepository,
        BioSessionRepositoryInterface $bioSessionRepository,
        BioAuthSessionRepositoryInterface $bioAuthSessionRepository,
        PrevClientRandomRepositoryInterface $prevClientRandomRepository
    ) {
        parent::__construct($bioClientRepository, $bioSessionRepository, $bioAuthSessionRepository);
        $this->prevClientRandomRepository = $prevClientRandomRepository;
    }

    /**
     * @param $ip_address
     * @param BioAuthV2ControllerInterface $endpoint
     * @return BioAuthV2ControllerInterface
     */
    public function performStage1($ip_address, BioAuthV2ControllerInterface $endpoint)
    {
        // Randomly generate unique session_id
        $session_id = $this->generateUnusedSessionId();
        // Save biometric_session
        $bioSession = $this->bioSessionRepository->add($session_id, null, $ip_address, null);
        // Instruct controller to respond
        return $endpoint->stage1SuccessResponse($session_id, self::SERVER_ID);
    }

    public function performStage2(
        $session_id,
        $client_id,
        $client_random,
        $client_mac,
        $ip_address,
        BioAuthV2ControllerInterface $endpoint
    ) {
        // Verify session_id is linked to a valid session
        $bioSession = $this->verifySessionId($session_id);
        if (!$bioSession) {
            return $endpoint->invalidSessionIdResponse();
        }
        // Verify client_id
        if (!$this->verifyClientIdNotMalformed($client_id)) {
            return $endpoint->invalidRequestResponse();
        }

        $bioClient = $this->verifyClientIdBelongsToValidClient($client_id);
        if (!$bioClient) {
            return $endpoint->invalidClientIdResponse();
        }
        // Verify client_random has not been used before by that client
        if ($this->prevClientRandomRepository->hasBeenUsedPreviously($bioClient->biometric_client_id, $client_random)) {
            $this->saveStage2SessionState($bioSession, $bioClient, $client_random, $ip_address);
            return $endpoint->invalidClientRandomResponse();
        }
        // Compute client_mac and verify provided is correct
        if (!$this->verifyClientMAC($bioClient, $bioSession, $client_random, $client_mac)) {
            $this->saveStage2SessionState($bioSession, $bioClient, $client_random, $ip_address);
            return $endpoint->invalidClientMACResponse();
        }
        // Calculate server_mac (server_id||client_random)
        $server_mac = $this->calculateServerMAC($bioSession, $bioClient, $client_random);
        // Create biometric authenticated session & save biometric_session state
        $bioAuthSession = $this->bioAuthSessionRepository->add($bioClient->biometric_client_id, $bioSession->biometric_session_id, self::BIO_AUTH_EXPIRY_TIME);
        $this->saveStage2SessionState($bioSession, $bioClient, $client_random, $ip_address);

        return $endpoint->stage2SuccessResponse($server_mac, $bioAuthSession->expires);
    }

    private function saveStage2SessionState(BiometricSession $bioSession, BiometricClient $bioClient, $client_random, $ip_address) {
        // Save client_random to nonce cache
        $this->prevClientRandomRepository->add($bioClient->biometric_client_id, $client_random);
        // Save biometric_session info
        $this->bioSessionRepository->update($bioSession->biometric_session_id, $client_random, $ip_address, null);
        // Associate to the biometric client
        $this->bioSessionRepository->associateSessionToClient($bioSession->biometric_session_id, $bioClient->biometric_client_id);
    }

}
