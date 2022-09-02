<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\Controller\Backend\Search;

use ApacheSolrForTypo3\Solr\Backend\IndexingConfigurationSelectorField;
use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\IndexQueue\QueueInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Form\Exception as BackendFormException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Index Queue Module
 *
 * @todo: Support all index queues in actions beside "initializeIndexQueueAction" and
 *        "resetLogErrorsAction"
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class IndexQueueModuleController extends AbstractModuleController
{
    /**
     * The default Solr Queue

     * @var QueueInterface
     */
    protected QueueInterface $indexQueue;

    /**
     * Enabled Solr index queues
     *
     * @var QueueInterface[]
     */
    protected array $enabledIndexQueues;

    /**
     * Initializes the controller before invoking an action method.
     */
    protected function initializeAction()
    {
        parent::initializeAction();

        $this->enabledIndexQueues = $this->getIndexQueues();
        if (!empty($this->enabledIndexQueues)) {
            $this->indexQueue = $this->enabledIndexQueues[Queue::class] ?? reset($this->enabledIndexQueues);
        }
    }

    /**
     * Sets the default queue to use
     *
     * @param QueueInterface $indexQueue
     */
    public function setIndexQueue(QueueInterface $indexQueue)
    {
        $this->indexQueue = $indexQueue;
    }

    /**
     * Lists the available indexing configurations
     *
     * @return ResponseInterface
     * @throws BackendFormException
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->canQueueSelectedSite()) {
            $this->view->assign('can_not_proceed', true);
            return $this->getModuleTemplateResponse();
        }

        $statistics = $this->indexQueue->getStatisticsBySite($this->selectedSite);
        $this->view->assign('indexQueueInitializationSelector', $this->getIndexQueueInitializationSelector());
        $this->view->assign('indexqueue_statistics', $statistics);
        $this->view->assign('indexqueue_errors', $this->indexQueue->getErrorsBySite($this->selectedSite));
        return $this->getModuleTemplateResponse();
    }

    /**
     * Checks if selected site can be queued.
     *
     * @return bool
     */
    protected function canQueueSelectedSite(): bool
    {
        if ($this->selectedSite === null || empty($this->solrConnectionManager->getConnectionsBySite($this->selectedSite))) {
            return false;
        }

        if (!isset($this->indexQueue)) {
            return false;
        }

        $enabledIndexQueueConfigurationNames = $this->selectedSite->getSolrConfiguration()->getEnabledIndexQueueConfigurationNames();
        if (empty($enabledIndexQueueConfigurationNames)) {
            return false;
        }
        return true;
    }

    /**
     * Renders a field to select which indexing configurations to initialize.
     *
     * Uses TCEforms.
     *
     * @return string Markup for the select field
     * @throws BackendFormException
     */
    protected function getIndexQueueInitializationSelector(): string
    {
        $selector = GeneralUtility::makeInstance(IndexingConfigurationSelectorField::class, $this->selectedSite);
        $selector->setFormElementName('tx_solr-index-queue-initialization');

        return $selector->render();
    }

    /**
     * Initializes the Index Queue for selected indexing configurations
     *
     * @return ResponseInterface
     */
    public function initializeIndexQueueAction(): ResponseInterface
    {
        $initializedIndexingConfigurations = [];

        $indexingConfigurationsToInitialize = GeneralUtility::_POST('tx_solr-index-queue-initialization');
        if ((!empty($indexingConfigurationsToInitialize)) && (is_array($indexingConfigurationsToInitialize))) {
            /** @var QueueInitializationService $initializationService */
            $initializationService = GeneralUtility::makeInstance(QueueInitializationService::class);
            foreach ($indexingConfigurationsToInitialize as $configurationToInitialize) {
                $indexQueueClass = $this->selectedSite->getSolrConfiguration()->getIndexQueueClassByConfigurationName($configurationToInitialize);
                $indexQueue = $this->enabledIndexQueues[$indexQueueClass];

                try {
                    $status = $initializationService->initializeBySiteAndIndexConfigurations($this->selectedSite, [$configurationToInitialize]);
                    $initializedIndexingConfiguration = [
                        'status' => $status[$configurationToInitialize],
                        'statistic' => 0,
                    ];
                    if ($status[$configurationToInitialize] === true) {
                        $initializedIndexingConfiguration['totalCount'] = $indexQueue->getStatisticsBySite($this->selectedSite, $configurationToInitialize)->getTotalCount();
                    }
                    $initializedIndexingConfigurations[$configurationToInitialize] = $initializedIndexingConfiguration;
                } catch (\Throwable $e) {
                    $this->addFlashMessage(
                        sprintf(
                            LocalizationUtility::translate(
                                'solr.backend.index_queue_module.flashmessage.initialize_failure',
                                'Solr'
                            ),
                            $e->getMessage(),
                            $e->getCode()
                        ),
                        LocalizationUtility::translate(
                            'solr.backend.index_queue_module.flashmessage.initialize_failure.title',
                            'Solr'
                        ),
                        FlashMessage::ERROR
                    );
                }
            }
        } else {
            $messageLabel = 'solr.backend.index_queue_module.flashmessage.initialize.no_selection';
            $titleLabel = 'solr.backend.index_queue_module.flashmessage.not_initialized.title';
            $this->addFlashMessage(
                LocalizationUtility::translate($messageLabel, 'Solr'),
                LocalizationUtility::translate($titleLabel, 'Solr'),
                FlashMessage::WARNING
            );
        }

        $messagesForConfigurations = [];
        foreach ($initializedIndexingConfigurations as $indexingConfigurationName => $initializationData) {
            if ($initializationData['status'] === true) {
                $messagesForConfigurations[] = $indexingConfigurationName . ' (' . $initializationData['totalCount'] . ' records)';
            } else {
                $this->addFlashMessage(
                    sprintf(
                        LocalizationUtility::translate(
                            'solr.backend.index_queue_module.flashmessage.initialize_failure',
                            'Solr'
                        ),
                        $indexingConfigurationName,
                        1662117020
                    ),
                    LocalizationUtility::translate(
                        'solr.backend.index_queue_module.flashmessage.initialize_failure.title',
                        'Solr'
                    ),
                    FlashMessage::ERROR
                );
            }
        }

        if (!empty($messagesForConfigurations)) {
            $messageLabel = 'solr.backend.index_queue_module.flashmessage.initialize.success';
            $titleLabel = 'solr.backend.index_queue_module.flashmessage.initialize.title';
            $this->addFlashMessage(
                LocalizationUtility::translate($messageLabel, 'Solr', [implode(', ', $messagesForConfigurations)]),
                LocalizationUtility::translate($titleLabel, 'Solr'),
                FlashMessage::OK
            );
        }

        return new RedirectResponse($this->uriBuilder->uriFor('index'), 303);
    }

    /**
     * Removes all errors in the index queue list. So that the items can be indexed again.
     *
     * @return ResponseInterface
     */
    public function resetLogErrorsAction(): ResponseInterface
    {
        foreach ($this->enabledIndexQueues as $queue) {
            $resetResult = $queue->resetAllErrors();

            $label = 'solr.backend.index_queue_module.flashmessage.success.reset_errors';
            $severity = FlashMessage::OK;
            if (!$resetResult) {
                $label = 'solr.backend.index_queue_module.flashmessage.error.reset_errors';
                $severity = FlashMessage::ERROR;
            }

            $this->addIndexQueueFlashMessage($label, $severity);
        }

        return new RedirectResponse($this->uriBuilder->uriFor('index'), 303);
    }

    /**
     * ReQueues a single item in the indexQueue.
     *
     * @param string $type
     * @param int $uid
     * @return ResponseInterface
     */
    public function requeueDocumentAction(string $type, int $uid): ResponseInterface
    {
        $label = 'solr.backend.index_queue_module.flashmessage.error.single_item_not_requeued';
        $severity = AbstractMessage::ERROR;

        $updateCount = $this->indexQueue->updateItem($type, $uid, time());
        if ($updateCount > 0) {
            $label = 'solr.backend.index_queue_module.flashmessage.success.single_item_was_requeued';
            $severity = AbstractMessage::OK;
        }

        $this->addIndexQueueFlashMessage($label, $severity);

        return new RedirectResponse($this->uriBuilder->uriFor('index'), 303);
    }

    /**
     * Shows the error message for one queue item.
     *
     * @param int $indexQueueItemId
     * @return ResponseInterface
     */
    public function showErrorAction(int $indexQueueItemId): ResponseInterface
    {
        $item = $this->indexQueue->getItem($indexQueueItemId);
        if ($item === null) {
            // add a flash message and quit
            $label = 'solr.backend.index_queue_module.flashmessage.error.no_queue_item_for_queue_error';
            $severity = FlashMessage::ERROR;
            $this->addIndexQueueFlashMessage($label, $severity);

            return new RedirectResponse($this->uriBuilder->uriFor('index'), 303);
        }

        $this->view->assign('indexQueueItem', $item);
        return $this->getModuleTemplateResponse();
    }

    /**
     * Indexes a few documents with the index service.
     * @return ResponseInterface
     */
    public function doIndexingRunAction(): ResponseInterface
    {
        /* @var IndexService $indexService */
        $indexService = GeneralUtility::makeInstance(IndexService::class, $this->selectedSite);
        $indexWithoutErrors = $indexService->indexItems(1);

        $label = 'solr.backend.index_queue_module.flashmessage.success.index_manual';
        $severity = FlashMessage::OK;
        if (!$indexWithoutErrors) {
            $label = 'solr.backend.index_queue_module.flashmessage.error.index_manual';
            $severity = FlashMessage::ERROR;
        }

        $this->addFlashMessage(
            LocalizationUtility::translate($label, 'Solr'),
            LocalizationUtility::translate('solr.backend.index_queue_module.flashmessage.index_manual', 'Solr'),
            $severity
        );

        return new RedirectResponse($this->uriBuilder->uriFor('index'), 303);
    }

    /**
     * Adds a flash message for the index queue module.
     *
     * @param string $label
     * @param int $severity
     */
    protected function addIndexQueueFlashMessage(string $label, int $severity)
    {
        $this->addFlashMessage(LocalizationUtility::translate($label, 'Solr'), LocalizationUtility::translate('solr.backend.index_queue_module.flashmessage.title', 'Solr'), $severity);
    }

    /**
     * Get index queues
     *
     * @return QueueInterface[]
     */
    protected function getIndexQueues(): array
    {
        $queues = [];
        $configuration = $this->selectedSite->getSolrConfiguration();
        foreach ($configuration->getEnabledIndexQueueConfigurationNames() as $indexingConfiguration) {
            $indexQueueClass = $configuration->getIndexQueueClassByConfigurationName($indexingConfiguration);
            if (!isset($queues[$indexQueueClass])) {
                $queues[$indexQueueClass] = GeneralUtility::makeInstance($indexQueueClass);
            }
        }

        return $queues;
    }
}
