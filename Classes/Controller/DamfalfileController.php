<?php

namespace DMK\Mkdam2fal\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015-2017 DMK E-BUSINESS GmbH (dev@dmk-ebusiness.de)
 *  (c) 2014 Daniel Hasse - websedit AG <extensions@websedit.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use DMK\Mkdam2fal\ServiceHelper\FileLogger;
use DMK\Mkdam2fal\Utility\ConfigUtility;
use Symfony\Component\Yaml\Exception\RuntimeException;
use TYPO3\CMS\Core\Messaging\AbstractMessage;

\tx_rnbase::load('tx_rnbase_util_Extensions');

/**
 * DamfalfileController
 */
class DamfalfileController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /**
     * damfalfileRepository
     *
     * @var \DMK\Mkdam2fal\Domain\Repository\DamfalfileRepository
     * @inject
     */
    protected $damfalfileRepository;

    /**
     * fileFolderRead
     *
     * @var \DMK\Mkdam2fal\ServiceHelper\FileFolderRead
     * @inject
     */
    protected $fileFolderRead;

    /**
     * backendSessionHandler
     *
     * @var \DMK\Mkdam2fal\ServiceHelper\BackendSession
     * @inject
     */
    protected $backendSessionHandler;

    /**
     * damFrontendConverter
     *
     * @var \DMK\Mkdam2fal\ServiceHelper\DamFrontendConverter
     * @inject
     */
    protected $damFrontendConverter;

    /**
     * action list
     *
     * @param string $executeDamUpdateSubmit
     * @param int    $debug not used
     * @param int    $processed
     *
     * @return void
     */
    public function listAction($executeDamUpdateSubmit = '', $debug = 0, $submitted = 0)
    {

        $this->view->assign('tabInteger', 0);
        $this->view->assign('logData', false);

        $pathSite = $this->getRightPath();
        $this->view->assign('pathSite', $pathSite);
        $this->view->assign('pathLogs', $this->getLogPath());
        // action for updating inserting the DAM-entrys from tx_dam
        $logger = new FileLogger('firstStep');

        // checks if there are files to import and get them; if there are no files redirect to referenceUpdateAction
        $txDamEntriesNotImported = $this->damfalfileRepository->getArrayDataFromTable(
            'uid, file_path, file_name, sys_language_uid, l18n_parent',
            'tx_dam',
            'damalreadyexported != 1 and deleted = 0',
            $groupBy = '',
            $orderBy = '',
            $limit = ConfigUtility::getDefaultLimit()
        );
        if ($txDamEntriesNotImported) {
            // if button was pressed start the tx_dam transfer
            if ($executeDamUpdateSubmit) {
                $logger->writeLog(sprintf('Found %d dam entries not processed!',
                    count($txDamEntriesNotImported)));
                // Verzeichnisliste mit Dateien ohne FAL-Referenz
                $pathList = array();

                foreach ($txDamEntriesNotImported as $rowDamEntriesNotImported) {
                    $logger->writeLog(sprintf('HANDLE FILE (DAM %d) %s  "%s" ',
                        $rowDamEntriesNotImported['uid'],
                        $rowDamEntriesNotImported['file_path'],
                        $rowDamEntriesNotImported['file_name']));

                    // get subpart from tx_dam.file_path to compare later on with sys_file.identifier; complete it to FAL identifier
                    // Die Variable ist ein String mit Verzeichnis- und Dateiname
                    $completeIdentifierForFAL = $this->damfalfileRepository->getIdentifier(
                        $rowDamEntriesNotImported['file_path'],
                        $rowDamEntriesNotImported['file_name'],
                        true // Make sure the imported file exists
                    );
                    $storageIdForFAL = $this->damfalfileRepository->getStorageForFile(
                        $rowDamEntriesNotImported['file_path'],
                        $rowDamEntriesNotImported['file_name']
                    );
                    $logger->writeLog(sprintf('  Identifier FAL: %s',
                        ($completeIdentifierForFAL ? $completeIdentifierForFAL : 'FILE NOT FOUND!')));
                    $logger->writeLog(sprintf('  StorageId for FAL: %d', $storageIdForFAL));

                    //if this is a translation record there will be no storage else no storage is bad
                    if (!$storageIdForFAL && $rowDamEntriesNotImported['l18n_parent'] == 0) {
                        $this->addFlashMessage(
                            sprintf("a storage for \"%s\" was not found (Identifier: %s)",
                                $rowDamEntriesNotImported['file_path'], $completeIdentifierForFAL),
                            'storage not found',
                            \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR,
                            true
                        );
                        // @TODO: create storage, if not exists!?
                        $this->redirect('list', null, null, null, null);
                    }

                    // Make sure the imported file exists
                    if (!$completeIdentifierForFAL) {
                        $logger->writeLog(sprintf('  Skip file because no FAL entry was found!'));
                        // if the file doesnt exist, just place the mark for imported, otherwise the unmarked file will block the process
                        $this->damfalfileRepository->updateDAMTableWithFALId($rowDamEntriesNotImported['uid'],
                            "0");
                        continue;
                    }

                    // Check if there is already a FAL record for this dam entry
                    // compare DAM with FAL entries in db in a foreach loop where
                    // tx_dam.file_path == sys_file.identifier and tx_dam.file_name == sys_file.name
                    // and sys_language_uid == sys_file_metadata.sys_language_uid
                    $foundFALEntry = $this->damfalfileRepository->selectOneRowQuery(
                        'file.uid',
                        'sys_file file, sys_file_metadata filemetadata',
                        "file.uid = filemetadata.file AND file.identifier = '" . $this->sanitizeName($completeIdentifierForFAL) .
                        "' AND file.name = '" . $this->sanitizeName($rowDamEntriesNotImported["file_name"]) .
                        "' AND file.storage = " . $storageIdForFAL .
                        " AND filemetadata.sys_language_uid = '" . $rowDamEntriesNotImported['sys_language_uid'] . "'",
                        $groupBy = '',
                        $orderBy = '',
                        $limit = ConfigUtility::getDefaultLimit()
                    );

                    if (!$foundFALEntry) {
                        // No fal record found in database, update pathList statistics
                        $path = $rowDamEntriesNotImported['file_path'];
                        if (!array_key_exists($path, $pathList)) {
                            $pathList[$path] = 1;
                        } else {
                            $pathList[$path] = $pathList[$path] + 1;
                        }
                    }

                    // if a FAL entry is found compare information and update it if necessary
                    if ($foundFALEntry["uid"] > 0) {
                        $logger->writeLog(sprintf('  Link to FAL entry %d', $foundFALEntry['uid']));

                        $this->damfalfileRepository->updateFALEntry($foundFALEntry['uid'],
                            $rowDamEntriesNotImported['uid']);

                        // else insert the DAM information into sys_file table
                    } else {
                        $this->createNewFalRecord($rowDamEntriesNotImported,
                            $completeIdentifierForFAL, $logger);
                    }
                }

                ksort($pathList);
                $logger->writeLog(print_r($pathList, true));
                $logger->writeLog('Anzahl ' . count($pathList));

                // Handle frontend group permission
                $this->damfalfileRepository->migrateFrontendGroupPermissions();
                $this->redirect('list', null, null, ['debug' => $debug, 'submitted' => 1], null);
            } elseif ($submitted) {
                // Verarbeitung hat stattgefunden, aber es sind immer noch Daten vorhanden...
                $logData = $logger->dump();
                $this->view->assign('logData', $logData);
            }
        } else {
            $logger->close();
            $this->redirect('referenceUpdate', null, null, null, null);
        }

        // get data for progress information
        $txDamEntriesProgressArray = $this->damfalfileRepository->getProgressArray('tx_dam',
            "damalreadyexported = '1'", '');
        $this->view->assign('txDamEntriesProgressArray', $txDamEntriesProgressArray);
        $logger->close();
    }

    /**
     * function to get server path
     *
     * @return string
     */
    public function getRightPath()
    {
        $pathSite = str_replace($_SERVER['DOCUMENT_ROOT'], '',
            \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mkdam2fal'));
        $pathSite = $_SERVER['HTTP_HOST'] . '/' . $pathSite;
        return $pathSite;
    }

    /**
     * function to get server path
     *
     * @return string
     */
    public function getLogPath()
    {
        return \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'typo3temp/mkdam2fal/logs/';
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public function sanitizeName($name)
    {
        return addslashes(stripslashes($name));
    }

    /**
     * Relevant for translated records only!
     *
     * @param array      $rowDamEntry
     * @param string     $completeIdentifierForFAL
     * @param FileLogger $logger
     */
    protected function createNewFalRecord(
        $rowDamEntry,
        $completeIdentifierForFAL,
        FileLogger $logger
    ) {
        // check if there is a parent-entry in tx_dam for the translation
        if ($this->isTranslatedDamRecord($rowDamEntry)) {
            $logger->writeLog(sprintf('  DAM record is translated!'));

            // get information from parent entry; file_path and file_name
            $damParentFileInfo = $this->damfalfileRepository->getDamParentInformation($rowDamEntry['l18n_parent']);

            //resolve storage ID from parent
            $storageIdForFAL = $this->damfalfileRepository->getStorageForFile(
                $damParentFileInfo['filepath'],
                $damParentFileInfo['filename']
            );

            // get subpart from tx_dam.file_path to compare later on with sys_file.identifier; complete it to FAL identifier
            $completeIdentifierForFALWithParentID = $this->damfalfileRepository->getIdentifier(
                $damParentFileInfo['filepath'],
                $damParentFileInfo['filename']);

            // compare DAM with FAL entries
            $foundFALEntryWithParentID = $this->damfalfileRepository->selectOneRowQuery(
                'uid',
                'sys_file file, sys_file_metadata filemetadata',
                "file.uid = filemetadata.file AND file.identifier = '" . addslashes($completeIdentifierForFALWithParentID) .
                "' AND file.name = '" . addslashes($damParentFileInfo['filename']) .
                "' and file.storage = " . $storageIdForFAL .
                " AND filemetadata.sys_language_uid = '" . $rowDamEntry['sys_language_uid'] . "'",
                $groupBy = '',
                $orderBy = '',
                $limit = ConfigUtility::getDefaultLimit()
            );

            // if a FAL entry is found compare information and update it if necessary
            if ($foundFALEntryWithParentID['uid'] > 0) {
                // still to watch and think over if it makes sense
                $this->damfalfileRepository->updateFALEntryWithParent($foundFALEntryWithParentID['uid'],
                    $rowDamEntry['uid'], $rowDamEntry['l18n_parent']);
            } else {
                // if a file entry exits but there is no filemetadata entry
                // test if a fal entry exists, if so then just do a filemetadata entry
                $foundFALEntryChecked = $this->damfalfileRepository->selectOneRowQuery(
                    'uid',
                    'sys_file',
                    "identifier = '" . addslashes($completeIdentifierForFALWithParentID) .
                    "' and name = '" . addslashes($damParentFileInfo['filename']) . "' AND storage = " . $storageIdForFAL,
                    $groupBy = '',
                    $orderBy = '',
                    $limit = ConfigUtility::getDefaultLimit()
                );
                // if a FAL entry is found, insert metadata
                if ($foundFALEntryChecked['uid'] > 0 && $rowDamEntry['sys_language_uid'] > 0) {
                    $this->damfalfileRepository->insertFALEntryMetadata($foundFALEntryChecked['uid'],
                        $rowDamEntry['uid'], $rowDamEntry['l18n_parent']);
                } else {
                    // update sotrage index should insert all files, so just update
                    //$this->damfalfileRepository->insertFalEntry($rowDamEntriesNotImported['uid']);
                    $foundFALEntryWhichHasNoFilemetadata = $this->damfalfileRepository->selectOneRowQuery(
                        'uid',
                        'sys_file',
                        "identifier = '" . $this->sanitizeName($completeIdentifierForFAL) .
                        "' AND name = '" . $this->sanitizeName($rowDamEntry["file_name"]) . "'",
                        $groupBy = '',
                        $orderBy = '',
                        $limit = ConfigUtility::getDefaultLimit()
                    );
                    $this->damfalfileRepository->updateFALEntry($foundFALEntryWhichHasNoFilemetadata['uid'],
                        $rowDamEntry['uid']);
                }
            }

        } else {
            $logger->writeLog(sprintf('  DAM record is not translated!'));
            // check if a fal entry exists but has no filemetadata entry
            // search for fal entry, comparing identifier and name
            $foundFALEntryWhichHasNoFilemetadata = $this->damfalfileRepository->selectOneRowQuery(
                'uid',
                'sys_file',
                "identifier = '" . $this->sanitizeName($completeIdentifierForFAL) .
                "' AND name = '" . $this->sanitizeName($rowDamEntry["file_name"]) . "'",
                $groupBy = '',
                $orderBy = '',
                $limit = ConfigUtility::getDefaultLimit()
            );

            // if a fal entry was found take that uid otherwise insert fal entry
            if ($foundFALEntryWhichHasNoFilemetadata) {
                // update fal entry
                $this->damfalfileRepository->updateFALEntry($foundFALEntryWhichHasNoFilemetadata['uid'],
                    $rowDamEntry['uid']);
                // create filemetadata entry
                $this->damfalfileRepository->insertFALEntryMetadata($foundFALEntryWhichHasNoFilemetadata['uid'],
                    $rowDamEntry['uid'], $rowDamEntry['l18n_parent']);
            } else {
                $logger->writeLog('  ERROR: File not found in FAL. You should update storage index with scheduler job first!!');
                // update storage index should insert all files, so just update
                // nothing should happen, because it should find sth before
                // $this->damfalfileRepository->insertFalEntry($rowDamEntriesNotImported['uid']);
            }
        }
    }

    protected function isTranslatedDamRecord($rowDamEntry)
    {
        return $rowDamEntry['uid'] > 0 and $rowDamEntry['l18n_parent'] > 0 and $rowDamEntry['sys_language_uid'] > 0;
    }

    /**
     * action referenceUpdate
     *
     * @param integer $tabInteger
     * @param array   $fieldnameToTablenameArray
     * @param string  $executeTablenameMultiselect
     * @param string  $chosenTablenames
     * @param string  $executeReferenceUpdateSubmit
     * @param string  $chosenExtension
     * @param array   $identifierArray
     * @param string  $executeReferenceUpdateIdentifierSubmit
     * @param string  $executeTTContentTestSubmit
     * @param string  $thumbnailTest
     * @param string  $rteFilelinkTest
     *
     * @return void
     */
    public function referenceUpdateAction(
        $tabInteger = 0,
        array $fieldnameToTablenameArray = array(),
        $executeTablenameMultiselect = '',
        $chosenTablenames = '',
        $executeReferenceUpdateSubmit = '',
        $chosenExtension = '',
        array $identifierArray = array(),
        $executeReferenceUpdateIdentifierSubmit = '',
        $executeTTContentTestSubmit = '',
        $thumbnailTest = '',
        $rteFilelinkTest = ''
    ) {

        // sets up the integer parameter for the tabs navigation
        $tabInteger = $this->backendSessionHandler->setOrGetSessionParameter($tabInteger,
            'tabInteger');
        if ($tabInteger == '' or $tabInteger == 0) {
            $tabInteger = 0;
        }
        $this->view->assign('tabInteger', $tabInteger);
        $logger = new FileLogger('steps2to5');

        // $identifierArray 0=chosenTablename, 1=damIdentifier, 2=FALIdentifier, 3=checkboxValue, 4 = damTablename

        $pathSite = $this->getRightPath();
        $this->view->assign('pathSite', $pathSite);
        $this->view->assign('pathLogs', $this->getLogPath());

        // action for updating inserting DAM-references from tx_dam_mm_ref; flag is dammmrefalreadyexported
        $chosenExtension = $this->backendSessionHandler->setOrGetSessionParameter($chosenExtension,
            'chosenExtension');
        $this->view->assign('chosenExtension', $chosenExtension);

        // action for updating counted sys_file_reference entries to update the given table in the database
        $chosenTablenames = $this->backendSessionHandler->setOrGetSessionParameter($chosenTablenames,
            'chosenTablenames');
        $this->view->assign('chosenTablenames', $chosenTablenames);

        $errorMarker = 0;
        // sys_file_reference should be empty in the beginning, so just insert references
        if ($executeReferenceUpdateIdentifierSubmit) {
            $logger->writeLog(sprintf('Start executeReferenceUpdateIdentifier for %s',
                $chosenExtension));

            $errorMessageArray = array();
            $errorMarker = 1;
            $counter = 0;

            // check the empty fal inputs, these values will not be imported
            foreach ($identifierArray as $key => $value) {
                // check if checkbox was checked, if yes then do not import but update tx_dam_mm_ref entries with dammmrefnoexportwanted = 1
                if ($value[3] == 'isChecked') {

                    // set dammmrefnoexportwanted to 1 referring to given ident
                    $this->damfalfileRepository->updateDAMMMRefTableWithNoImportWanted($value[1]);

                } else {

                    if ($value[2] != '' || $value[2] != 0) {

                        // check if source was deleted, if yes do not copy
                        $mmRefInfo = $this->damfalfileRepository->getArrayDataFromTable(
                            '*',
                            'tx_dam_mm_ref',
                            "tablenames = '" . $value[4] . "' and ident = '" . $value[1] . "' and dammmrefalreadyexported != 1",
                            $groupBy = '',
                            $orderBy = '',
                            $limit = ConfigUtility::getDefaultLimit()
                        );


                        #$this->debug($mmRefInfoSSS);
                        #$this->debug($value);


                        foreach ($mmRefInfo as $rowMmRefInfo) {

                            // check foreign reference -> tablename
                            $fields = 'uid';

                            if (!empty($GLOBALS['TCA'][$rowMmRefInfo['tablenames']]['ctrl']['languageField'])) {
                                $fields .= ',sys_language_uid';
                            }
                            $existingReferenceForeign = $this->damfalfileRepository->selectOneRowQuery($fields,
                                $rowMmRefInfo['tablenames'],
                                "uid = '" . $rowMmRefInfo['uid_foreign'] . "' and deleted != 1");


                            if (is_array($existingReferenceForeign) &&
                                !empty($GLOBALS['TCA'][$rowMmRefInfo['tablenames']]['ctrl']['languageField'])) {
                                $existingReferenceForeign["sys_language_uid"] = 0;
                            }
                            if ($existingReferenceForeign) {

                                // check local reference -> tx_dam
                                $existingReferenceLocal = $this->damfalfileRepository->selectOneRowQuery('falUid',
                                    'tx_dam',
                                    "uid = '" . $rowMmRefInfo['uid_local'] . "' and deleted != 1 and falUid != 0");
                                if ($existingReferenceLocal) {

                                    if ($existingReferenceLocal['falUid'] != '' and $existingReferenceLocal['falUid'] > 0) {

                                        // check if there is an existing entry in the sys_file_reference comparing with sys_language_uid, just to be sure
                                        // to see if there is already a reference; compare sys_file_reference.uid_local == getSysFileUid and sys_file_reference.uid_foreign == tx_dam_mm_ref.uid_foreign and sys_file_reference.tablename == tx_dam_mm_ref.tablename and sys_file_reference.sys_language_uid == getTheRightLangUid
                                        // $existingSysFileReference = $this->damfalfileRepository->selectOneRowQuery("uid", "sys_file_reference", "uid_foreign = '".$rowMmRefInfo["uid_foreign"]."' and uid_local = '".$existingReferenceLocal["falUid"]."' and sys_language_uid = '".$existingReferenceForeign["sys_language_uid"]."' and tablenames = '".$value[0]."' and fieldname = '".$value[1]."'");
                                        if ($value[4] != $value[0]) {
                                            $tablenameGiven = $value[0];
                                        } else {
                                            $tablenameGiven = $value[4];
                                        }
                                        $existingSysFileReference = $this->damfalfileRepository->selectOneRowQuery('uid',
                                            'sys_file_reference',
                                            "uid_foreign = '" . $rowMmRefInfo['uid_foreign'] . "' and uid_local = '" . $existingReferenceLocal['falUid'] . "' and sys_language_uid = '" . $existingReferenceForeign['sys_language_uid'] . "' and tablenames = '" . $tablenameGiven . "' and fieldname = '" . $value[1] . "'");
                                        try {
                                            if ($existingSysFileReference) {
                                                // update, just for tt_content
                                                $this->damfalfileRepository->updateSysFileReference($existingSysFileReference['uid'],
                                                    $rowMmRefInfo['uid_foreign'], $tablenameGiven,
                                                    $rowMmRefInfo['uid_local'], $value[1]);
                                            } else {
                                                // insert
                                                $this->damfalfileRepository->insertSysFileReference($existingReferenceLocal['falUid'],
                                                    $rowMmRefInfo['uid_foreign'], $tablenameGiven,
                                                    $value[2],
                                                    $existingReferenceForeign['sys_language_uid'],
                                                    $rowMmRefInfo['uid_local'], $value[1],
                                                    $rowMmRefInfo['tablenames'],
                                                    $rowMmRefInfo['ident']);
                                            }
                                        } catch (RuntimeException $e) {
                                            $errorMessageArray[$counter]['message'] = $e->getMessage();
                                            $errorMessageArray[$counter]['tablename'] = $value[4] . ' ' . $value[0];
                                            $errorMessageArray[$counter]['identifier'] = $value[1] . ' ' . $value[2];
                                            $errorMessageArray[$counter]['uid_local'] = $rowMmRefInfo['uid_local'];
                                            $errorMessageArray[$counter]['uid_foreign'] = $rowMmRefInfo['uid_foreign'];
                                            $errorMarker = 2;
                                        }
                                    } else {
                                        $errorMessageArray[$counter]['message'] = 'noFALIdWasFoundInDAMTable';
                                        $errorMessageArray[$counter]['tablename'] = $value[4] . ' ' . $value[0];
                                        $errorMessageArray[$counter]['identifier'] = $value[1] . ' ' . $value[2];
                                        $errorMessageArray[$counter]['uid_local'] = $rowMmRefInfo['uid_local'];
                                        $errorMessageArray[$counter]['uid_foreign'] = $rowMmRefInfo['uid_foreign'];
                                        $errorMarker = 2;
                                    }
                                } else {
                                    $errorMessageArray[$counter]['message'] = 'noLocalSourceFound or FALUid is 0';
                                    $errorMessageArray[$counter]['tablename'] = $value[4] . ' ' . $value[0];
                                    $errorMessageArray[$counter]['identifier'] = $value[1] . ' ' . $value[2];
                                    $errorMessageArray[$counter]['uid_local'] = $rowMmRefInfo['uid_local'];
                                    $errorMessageArray[$counter]['uid_foreign'] = $rowMmRefInfo['uid_foreign'];
                                    $errorMarker = 2;
                                }
                            } else {
                                $errorMessageArray[$counter]['message'] = 'noForeignSourceFound or deleted';
                                $errorMessageArray[$counter]['tablename'] = $value[4] . ' ' . $value[0];
                                $errorMessageArray[$counter]['identifier'] = $value[1] . ' ' . $value[2];
                                $errorMessageArray[$counter]['uid_local'] = $rowMmRefInfo['uid_local'];
                                $errorMessageArray[$counter]['uid_foreign'] = $rowMmRefInfo['uid_foreign'];
                                $errorMarker = 2;
                            }
                            $counter++;
                        }
                    } else {
                        $errorMessageArray[$counter]['message'] = 'noFALValueFilled';
                        $errorMessageArray[$counter]['tablename'] = $value[4] . ' ' . $value[0];
                        $errorMessageArray[$counter]['identifier'] = $value[1] . ' ' . $value[2];
                        $errorMarker = 2;
                    }
                    $counter++;
                }
            }
        }

        if ($executeReferenceUpdateSubmit) {
            // Klick auf Submit in Tab 2
            $logger->writeLog(sprintf('Start executeReferenceUpdate for %s', $chosenExtension));

            $mmRefTablenames = $this->damfalfileRepository->getArrayDataFromTable(
                'ident, tablenames',
                'tx_dam_mm_ref',
                "dammmrefnoexportwanted != 1 AND dammmrefalreadyexported != 1 AND tablenames LIKE '%" . $chosenExtension . "%'",
                $groupBy = 'ident, tablenames',
                $orderBy = 'tablenames',
                $limit = ConfigUtility::getDefaultLimit()
            );
            // get idents, tablenames
            // Die Daten sind für den Aufbau der GUI notwendig
            $damIdents = array();
            $logger->writeLog(sprintf('  Found %d unprocessed records in tx_dam_mm_ref',
                count($mmRefTablenames)));

            foreach ($mmRefTablenames as $rowMmRefTablenames) {
                // fill array with std values for tt_content and pages
                if ($rowMmRefTablenames['tablenames'] == 'tt_content') {
                    if ($rowMmRefTablenames['ident'] == 'tx_damttcontent_files') {
                        $stdValueForFALIdentifier = 'image';
                    } elseif ($rowMmRefTablenames['ident'] == 'tx_damfilelinks_filelinks') {
                        $stdValueForFALIdentifier = 'media';
                    } else {
                        $stdValueForFALIdentifier = '';
                    }
                } elseif ($rowMmRefTablenames['tablenames'] == 'pages') {
                    if ($rowMmRefTablenames['ident'] == 'tx_dampages_files') {
                        $stdValueForFALIdentifier = 'media';
                    } else {
                        $stdValueForFALIdentifier = '';
                    }
                } else {
                    $stdValueForFALIdentifier = '';
                }
                $damIdents[] = array(
                    $rowMmRefTablenames['tablenames'],
                    $rowMmRefTablenames['ident'],
                    $stdValueForFALIdentifier
                );
            }

            $countedRelationsTotal = $this->damfalfileRepository->getArrayDataFromTable(
                'COUNT(*) AS countedNumber',
                'tx_dam_mm_ref',
                "dammmrefnoexportwanted != 1 AND dammmrefalreadyexported != 1 AND tablenames LIKE '%" . $chosenExtension . "%'",
                $groupBy = '',
                $orderBy = '',
                $limit = ConfigUtility::getDefaultLimit()
            );

            $this->view->assign('countedRelationsTotal',
                $countedRelationsTotal[1]['countedNumber']);
            $this->view->assign('damIdents', $damIdents);

        }

        // save in an array the given identifiers for FAL the user sets up in the backend module, key is the tx_dam_mm_ref.ident
        // create select field
        $extensionNameUnique = $this->damfalfileRepository->getExtensionNamesForMultiselect();
        $this->view->assign('extensionNames', $extensionNameUnique);

        // get data for progress information
        $txDamEntriesProgressArray = $this->damfalfileRepository->getProgressArray('tx_dam',
            "damalreadyexported = '1'", '');
        $this->view->assign('txDamEntriesProgressArray', $txDamEntriesProgressArray);

        // write error log if necessary
        if ($errorMessageArray and $errorMarker == 2) {
            $logFile = $this->fileFolderRead->writeLog($chosenExtension, $errorMessageArray, '');
        }

        $this->view->assign('errors',
            count($errorMessageArray) > 500 ? array(0 => array('message' => 'Too many errors. See LOG: ' . $logFile)) : $errorMessageArray);
        $this->view->assign('errorMarker', $errorMarker);

        // get filename from Logs folder to create download buttons
        $folderFilenamesLog = $this->fileFolderRead->getFolderFilenames(PATH_site . 'typo3temp/mkdam2fal/logs/');

        $this->view->assign('folderFilenamesLog', $folderFilenamesLog);

        // check tt_content table
        // check if all tt_content data from tx_dam_mm_ref is already imported into fal
        $ttContentCheck = $this->damfalfileRepository->getArrayDataFromTable('Count(uid_local) AS countedrows',
            'tx_dam_mm_ref',
            "dammmrefnoexportwanted != 1 AND dammmrefalreadyexported != 1 AND tablenames LIKE 'tt_content'",
            $groupBy = '', $orderBy = '', $limit = '');

        if ($ttContentCheck[1]['countedrows'] == 0) {
            $this->view->assign('ttContentCheck', $ttContentCheck);
        }
        if ($executeTTContentTestSubmit) {
            // Das ist Tab 3

            if ($thumbnailTest) {

                $countedImageMediaArray = array();

                // get all sys_file_references with tablename tt_content
                $ttContentEntriesInFileReference = $this->damfalfileRepository->getArrayDataFromTable('*',
                    'sys_file_reference',
                    "tablenames = 'tt_content' AND (fieldname = 'image' OR fieldname = 'media') and table_local = 'sys_file' AND deleted <> 1",
                    $groupBy = '', $orderBy = '', $limit = '');

                foreach ($ttContentEntriesInFileReference as $value) {
                    if ($value['fieldname'] == 'image') {
                        $countedImageMediaArray[$value['uid_foreign']]['image'] = $countedImageMediaArray[$value['uid_foreign']]['image'] + 1;
                    }
                    if ($value['fieldname'] == 'media') {
                        $countedImageMediaArray[$value['uid_foreign']]['media'] = $countedImageMediaArray[$value['uid_foreign']]['media'] + 1;
                    }
                    // if ($value['fieldname'] == 'image'){$countedImageMediaArray[$value['uid_foreign']]['image'] = 0;}
                    // if ($value['fieldname'] == 'media'){$countedImageMediaArray[$value['uid_foreign']]['media'] = 0;}
                }

                foreach ($countedImageMediaArray as $keyCounted => $imageOrMediaValueArray) {
                    $fieldarray = array();
                    foreach ($imageOrMediaValueArray as $key => $imageOrMediaValue) {
                        $fieldarray = array(
                            $key => $imageOrMediaValue
                        );
                    }
                    $this->damfalfileRepository->updateTableEntry('tt_content',
                        "uid = '" . $keyCounted . "'", $fieldarray);
                }
            }

            if ($rteFilelinkTest) {
                $infos = array();

                if ($infoArr = $this->convertRteMediaTag4ttcontent()) {
                    $infos[] = $infoArr;
                }
                if ($infoArr = $this->convertRteMediaTag4ttnews()) {
                    $infos[] = $infoArr;
                }
                if ($infoArr = $this->convertRteMediaTag4irfaq()) {
                    $infos[] = $infoArr;
                }
                if (!empty($infos)) {
                    $this->view->assign('infos', $infos);
                }
            }
        }

        // check if dam categories, tx_dam_cat, table is available
        $txDamCatExist = $this->damfalfileRepository->tableOrColumnFieldExist('tx_dam_cat', 'table',
            '');

        if ($txDamCatExist == true) {
            // category interface generation
            // get data for progress category
            $categoryProgressArray = $this->damfalfileRepository->getProgressArray('tx_dam_cat',
                "damcatalreadyexported = '1'", '');

            $categoryReferenceProgressArray = $this->damfalfileRepository->getProgressArray('tx_dam_mm_cat',
                "dammmcatalreadyexported = '1'", '');

            $this->view->assign('categoryProgressArray', $categoryProgressArray);
            $this->view->assign('categoryReferenceProgressArray', $categoryReferenceProgressArray);
        }

        // dropdown for multiselect tablenames from sys_file_reference and exec db tables with given parameters
        $tablenamesForMultiselect = $this->damfalfileRepository->getTablenamesForMultiselect();
        $this->view->assign('tablenamesForMultiselect', $tablenamesForMultiselect);
        if ($executeTablenameMultiselect) {
            // get fieldnames by chosen tablename from sys_file_reference
            $fieldnamesFromTablenames = $this->damfalfileRepository->getArrayDataFromTable('fieldname',
                'sys_file_reference', "tablenames = '" . $chosenTablenames . "'",
                $groupBy = 'fieldname', $orderBy = '', $limit = '');
            $this->view->assign('fieldnamesFromTablenames', $fieldnamesFromTablenames);
        }
        // updates database foreign table columns with given parameters
        if ($fieldnameToTablenameArray) {
            foreach ($fieldnameToTablenameArray as $keyFieldnameToTablename => $valueFieldnameToTablename) {
                if ($valueFieldnameToTablename[2] != 'isChecked') {
                    if ($valueFieldnameToTablename[1]) {
                        // count sys_file_reference entries with given identifier sorted by foreign_uid
                        // get foreign_uid from sys_file_reference
                        $this->damfalfileRepository->getCountedUidForeignsFromSysFileReference($valueFieldnameToTablename[0],
                            $chosenTablenames, $valueFieldnameToTablename[1]);
                    } else {
                        // no valueFieldnameToTablename given
                    }
                }
            }
        }
        $logger->close();

        //Convert DAM Frontend plugin
        $this->view->assign('damFePluginProgressArray', $this->damFrontendPluginProgress());
    }

    /**
     * Media-Tags für tt_content aktualisieren
     */
    private function convertRteMediaTag4ttcontent()
    {
        $info = array('table' => 'tt_content', 'skipped' => 0, 'success' => 0, 'records' => 0);
        $rteColumn = 'bodytext';
        $ttContentEntriesBodytext = $this->damfalfileRepository->getArrayDataFromTable('uid, ' . $rteColumn,
            'tt_content',
            'deleted <> 1 AND ' . $rteColumn . ' IS NOT NULL', $groupBy = '', $orderBy = '',
            $limit = '');
        foreach ($ttContentEntriesBodytext as $bodytextValue) {
            $info['records'] += 1;
            $falLinkBodytext = $bodytextValue[$rteColumn];

            $matches = array();
            preg_match_all("/<media ([0-9]{1,})/", $falLinkBodytext, $matches);
            if (count($matches[1]) > 0) {
                $doUpdate = $this->convertRTEMedia2Link($falLinkBodytext, $matches, $info);
                if (!$doUpdate) {
                    continue;
                }

                $fieldsValues = array(
                    $rteColumn => $falLinkBodytext
                );

                $this->damfalfileRepository->updateTableEntry('tt_content',
                    "uid = '" . $bodytextValue['uid'] . "'", $fieldsValues);
                $info['success'] += 1;
            }
        }
        return $info;
    }

    /**
     * Iteriert über alle Treffen in einem RTE-Feld und ersetzt die DAM-UIDs durch die FAL-UIDs.
     *
     * @param unknown $falLinkBodytext
     * @param unknown $matches
     * @param unknown $info
     *
     * @return boolean
     */
    private function convertRTEMedia2Link(&$falLinkBodytext, $matches, &$info)
    {
        $doUpdate = true;
        foreach ($matches[1] as $match) {
            $rowDamInfo = $this->damfalfileRepository->selectOneRowQuery('falUid', 'tx_dam',
                "uid = '" . $match . "'");
            if ($rowDamInfo['falUid'] == 0) {
                // Die Datei wurde nicht konvertiert. Wir lassen den Datensatz besser unverändert, sonst
                // Geht die Relation komplett verloren
                $info['skipped'] += 1;
                $doUpdate = false;
            }
            $falLinkBodytext = str_replace('<media ' . $match, '<media ' . $rowDamInfo['falUid'],
                $falLinkBodytext);
        }
        if ($doUpdate) {
            $falLinkBodytext = str_replace('<media ', '<link file:', $falLinkBodytext);
            $falLinkBodytext = str_replace('</media>', '</link>', $falLinkBodytext);
        }

        return $doUpdate;
    }

    /**
     * Media-Tags für tt_news aktualisieren, falls die Extension vorhanden ist
     */
    private function convertRteMediaTag4ttnews()
    {
        if (!\tx_rnbase_util_Extensions::isLoaded('tt_news')) {
            return;
        }

        $info = array('table' => 'tt_news', 'skipped' => 0, 'success' => 0, 'records' => 0);
        $rteColumn = 'bodytext';
        $ttContentEntriesBodytext = $this->damfalfileRepository->getArrayDataFromTable('uid, ' . $rteColumn,
            'tt_news', 'deleted <> 1 AND ' . $rteColumn . ' IS NOT NULL', $groupBy = '',
            $orderBy = '', $limit = '');

        foreach ($ttContentEntriesBodytext as $bodytextValue) {
            $info['records'] += 1;
            $falLinkBodytext = $bodytextValue[$rteColumn];

            $matches = array();
            preg_match_all("/<media ([0-9]{1,})/", $falLinkBodytext, $matches);
            if (count($matches[1]) > 0) {
                $doUpdate = $this->convertRTEMedia2Link($falLinkBodytext, $matches, $info);
                if (!$doUpdate) {
                    continue;
                }

                $fieldsValues = array(
                    $rteColumn => $falLinkBodytext
                );

                $this->damfalfileRepository->updateTableEntry('tt_news',
                    "uid = '" . $bodytextValue['uid'] . "'", $fieldsValues);
                $info['success'] += 1;
            }
        }
        return $info;
    }

    /**
     * Media-Tags für irfaq aktualisieren, falls die Extension vorhanden ist
     */
    private function convertRteMediaTag4irfaq()
    {
        if (!\tx_rnbase_util_Extensions::isLoaded('irfaq')) {
            return;
        }

        $info = array('table' => 'tx_irfaq_q', 'skipped' => 0, 'success' => 0, 'records' => 0);
        $rteColumn = 'a';
        $rteFieldData = $this->damfalfileRepository->getArrayDataFromTable('uid, ' . $rteColumn,
            'tx_irfaq_q',
            'deleted <> 1 AND ' . $rteColumn . ' IS NOT NULL AND ' . $rteColumn . ' like \'%media%\'');

        foreach ($rteFieldData as $bodytextValue) {
            $info['records'] += 1;
            $falLinkBodytext = $bodytextValue[$rteColumn];

            $matches = array();
            preg_match_all("/<media ([0-9]{1,})/", $falLinkBodytext, $matches);
            if (count($matches[1]) > 0) {
                $doUpdate = $this->convertRTEMedia2Link($falLinkBodytext, $matches, $info);
                if (!$doUpdate) {
                    continue;
                }
                // $falLinkBodytext = str_replace('<link file:', '<media ', $falLinkBodytext);
                // $falLinkBodytext = str_replace('</link>', '</media>', $falLinkBodytext);

                $fieldsValues = array(
                    $rteColumn => $falLinkBodytext
                );
                $this->damfalfileRepository->updateTableEntry('tx_irfaq_q',
                    "uid = '" . $bodytextValue['uid'] . "'", $fieldsValues);
                $info['success'] += 1;
            }
        }

        return $info;
    }

    /**
     * @return array
     */
    protected function damFrontendPluginProgress()
    {
        // get filename from Logs folder to create download buttons
        $damFeFilenamesLog = $this->fileFolderRead->getFolderFilenames(
            PATH_site . 'typo3temp/mkdam2fal/logs/', 'log_dam_frontend'
        );

        $progress = array(
            'plugins' => $this->damFrontendConverter->getDamFrontendPlugins(),
            'output' => $this->damFrontendConverter->getOutput(),
            'damFeFilenamesLog' => $damFeFilenamesLog
        );

        return $progress;
    }

    /**
     * action updateCategory
     *
     * @param string $executeCategoryUpdateSubmit
     *
     * @return void
     */
    public function updateCategoryAction($executeCategoryUpdateSubmit = '')
    {

        if ($executeCategoryUpdateSubmit) {
            // insert all non imported categories
            try {
                $this->damfalfileRepository->insertCategory();
            } catch (\Exception $e) {
                $this->addFlashMessage($e->getMessage(), 'Error Updating Categories',
                    AbstractMessage::ERROR);
            }
        }

        $arguments = array('tabInteger' => 3);

        $this->redirect('referenceUpdate', null, null, $arguments, null);
    }

    /**
     * action updateDamFrontend
     *
     * @param string $executeupdateDamFrontendSubmit
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function updateDamFrontendAction($executeupdateDamFrontendSubmit = '')
    {
        if ($executeupdateDamFrontendSubmit) {
            $this->damFrontendConverter->convertDamFeToFileLinks();
            $output = array('convert' => $this->damFrontendConverter->getOutput());

            if ($output) {
                $logFile = $this->fileFolderRead->writeLog('dam_frontend', $output, '');
            }
        }

        $arguments = array('tabInteger' => 4);

        $this->redirect('referenceUpdate', null, null, $arguments, null);
    }

    /**
     * Debug Funktion
     *
     * @param type $value
     */
    private function debug($value)
    {
        \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($value);
    }

}
