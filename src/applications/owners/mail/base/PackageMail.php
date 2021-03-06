<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

abstract class PackageMail {

  protected $package;
  protected $handles;
  protected $owners;
  protected $paths;
  protected $mailTo;

  public function __construct($package) {
    $this->package = $package;
  }

  abstract protected function getVerb();

  abstract protected function isNewThread();

  final protected function getPackage() {
    return $this->package;
  }

  final protected function getHandles() {
    return $this->handles;
  }

  final protected function getOwners() {
    return $this->owners;
  }

  final protected function getPaths() {
    return $this->paths;
  }

  final protected function getMailTo() {
    return $this->mailTo;
  }

  final protected function renderPackageTitle() {
    return $this->getPackage()->getName();
  }

  final protected function renderRepoSubSection($repository_phid, $paths) {
    $handles = $this->getHandles();
    $section = array();
    $section[] = '  In repository '.$handles[$repository_phid]->getName().
      ' - '. PhabricatorEnv::getProductionURI($handles[$repository_phid]
      ->getURI());
    foreach ($paths as $path => $ignored) {
      $section[] = '    '.$path;
    }

    return implode("\n", $section);
  }

  protected function needSend() {
    return true;
  }

  protected function loadData() {
    $package = $this->getPackage();
    $owners = $package->loadOwners();
    $this->owners = $owners;

    $owner_phids = mpull($owners, 'getUserPHID');
    $primary_owner_phid = $package->getPrimaryOwnerPHID();
    $mail_to = $owner_phids;
    if (!in_array($primary_owner_phid, $owner_phids)) {
      $mail_to[] = $primary_owner_phid;
    }
    $this->mailTo = $mail_to;

    $paths = $package->loadPaths();
    $this->paths = mgroup($paths, 'getRepositoryPHID', 'getPath');

    $phids = array_mergev(array(
      $this->mailTo,
      array($package->getActorPHID()),
      array_keys($this->paths),
    ));
    $this->handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
  }

  final protected function renderSummarySection() {
    $package = $this->getPackage();
    $handles = $this->getHandles();
    $section = array();
    $section[] = $handles[$package->getActorPHID()]->getName().' '.
      strtolower($this->getVerb()).' '.$this->renderPackageTitle().'.';
    $section[] = '';

    $section[] = 'PACKAGE DETAIL';
    $section[] = '  '.PhabricatorEnv::getProductionURI(
      '/owners/package/'.$package->getID().'/');

    return implode("\n", $section);
  }

  protected function renderDescriptionSection() {
    return "PACKAGE DESCRIPTION\n".
      '  '.$this->getPackage()->getDescription();
  }

  protected function renderPrimaryOwnerSection() {
    $handles = $this->getHandles();
    return "PRIMARY OWNER\n".
      '  '.$handles[$this->getPackage()->getPrimaryOwnerPHID()]->getName();
  }

  protected function renderOwnersSection() {
    $handles = $this->getHandles();
    $owners = $this->getOwners();
    if (!$owners) {
      return null;
    }

    $owners = mpull($owners, 'getUserPHID');
    $owners = array_select_keys($handles, $owners);
    $owners = mpull($owners, 'getName');
    return "OWNERS\n".
      '  '.implode(', ', $owners);
  }

  protected function renderAuditingEnabledSection() {
    return "AUDITING ENABLED STATUS\n".
      '  '.($this->getPackage()->getAuditingEnabled() ? 'Enabled' : 'Disabled');
  }

  protected function renderPathsSection() {
    $section = array();
    $section[] = 'PATHS';
    foreach ($this->paths as $repository_phid => $paths) {
      $section[] = $this->renderRepoSubSection($repository_phid, $paths);
    }

    return implode("\n", $section);
  }

  final protected function renderBody() {
    $body = array();
    $body[] = $this->renderSummarySection();
    $body[] = $this->renderDescriptionSection();
    $body[] = $this->renderPrimaryOwnerSection();
    $body[] = $this->renderOwnersSection();
    $body[] = $this->renderAuditingEnabledSection();
    $body[] = $this->renderPathsSection();
    $body = array_filter($body);
    return implode("\n\n", $body);
  }

  final public function send() {
    $mails = $this->prepareMails();

    foreach ($mails as $mail) {
      $mail->saveAndSend();
    }
  }

  final public function prepareMails() {
    if (!$this->needSend()) {
      return array();
    }

    $this->loadData();

    $package = $this->getPackage();
    $prefix = PhabricatorEnv::getEnvConfig('metamta.package.subject-prefix');
    $verb = $this->getVerb();
    $package_title = $this->renderPackageTitle();
    $subject = trim("{$prefix} {$package_title}");
    $vary_subject = trim("{$prefix} [{$verb}] {$package_title}");
    $threading = $this->getMailThreading();
    list($thread_id, $thread_topic) = $threading;

    $template = id(new PhabricatorMetaMTAMail())
      ->setSubject($subject)
      ->setVarySubject($vary_subject)
      ->setFrom($package->getActorPHID())
      ->setThreadID($thread_id, $this->isNewThread())
      ->addHeader('Thread-Topic', $thread_topic)
      ->setRelatedPHID($package->getPHID())
      ->setIsBulk(true)
      ->setBody($this->renderBody());

    $reply_handler = $this->newReplyHandler();
    $mails = $reply_handler->multiplexMail(
      $template,
      array_select_keys($this->getHandles(), $this->getMailTo()),
      array());
    return $mails;
  }

  private function getMailThreading() {
    return array(
      'package-'.$this->getPackage()->getPHID(),
      'package '.$this->getPackage()->getPHID(),
    );
  }

  private function newReplyHandler() {
    $reply_handler = PhabricatorEnv::newObjectFromConfig(
      'metamta.package.reply-handler');
    $reply_handler->setMailReceiver($this->getPackage());
    return $reply_handler;
  }

}
