<?php

/*
 * Copyright 2011 Facebook, Inc.
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

/**
 * Handle major edit operations to DifferentialRevision -- adding and removing
 * reviewers, diffs, and CCs. Unlike simple edits, these changes trigger
 * complicated email workflows.
 */
class DifferentialRevisionEditor {

  protected $revision;
  protected $actorPHID;

  protected $cc         = null;
  protected $reviewers  = null;
  protected $diff;
  protected $comments;
  protected $silentUpdate;

  public function __construct(DifferentialRevision $revision, $actor_phid) {
    $this->revision = $revision;
    $this->actorPHID = $actor_phid;
  }

/*
  public static function newRevisionFromRawMessageWithDiff(
    DifferentialRawMessage $message,
    Diff $diff,
    $user) {

    if ($message->getRevisionID()) {
      throw new Exception(
        "The provided commit message is already associated with a ".
        "Differential revision.");
    }

    if ($message->getReviewedByNames()) {
      throw new Exception(
        "The provided commit message contains a 'Reviewed By:' field.");
    }

    $revision = new DifferentialRevision();
    $revision->setPHID($revision->generatePHID());

    $revision->setOwnerID($user);
    $revision->setStatus(DifferentialRevisionStatus::NEEDS_REVIEW);
    $revision->attachReviewers(array());
    $revision->attachCCPHIDs(array());

    $editor = new DifferentialRevisionEditor($revision, $user);

    self::copyFields($editor, $revision, $message, $user);

    $editor->addDiff($diff, null);
    $editor->save();

    return $revision;
  }

  public static function newRevisionFromConduitWithDiff(
    array $fields,
    Diff $diff,
    $user) {

    $revision = new DifferentialRevision();
    $revision->setPHID($revision->generatePHID());

    $revision->setOwnerID($user);
    $revision->setStatus(DifferentialRevisionStatus::NEEDS_REVIEW);
    $revision->attachReviewers(array());
    $revision->attachCCPHIDs(array());

    $editor = new DifferentialRevisionEditor($revision, $user);

    $editor->copyFieldFromConduit($fields);

    $editor->addDiff($diff, null);
    $editor->save();

    return $revision;
  }


  public static function copyFields(
    DifferentialRevisionEditor $editor,
    DifferentialRevision $revision,
    DifferentialRawMessage $message,
    $user) {

    $revision->setName($message->getTitle());
    $revision->setSummary($message->getSummary());
    $revision->setTestPlan($message->getTestPlan());
    $revision->setSVNBlameRevision($message->getBlameRevision());
    $revision->setRevert($message->getRevertPlan());
    $revision->setPlatformImpact($message->getPlatformImpact());
    $revision->setBugzillaID($message->getBugzillaID());

    $editor->setReviewers($message->getReviewerPHIDs());
    $editor->setCCPHIDs($message->getCCPHIDs());
  }

  public function copyFieldFromConduit(array $fields) {

    $user = $this->actorPHID;
    $revision = $this->revision;

    $revision->setName($fields['title']);
    $revision->setSummary($fields['summary']);
    $revision->setTestPlan($fields['testPlan']);
    $revision->setSVNBlameRevision($fields['blameRevision']);
    $revision->setRevert($fields['revertPlan']);
    $revision->setPlatformImpact($fields['platformImpact']);
    $revision->setBugzillaID($fields['bugzillaID']);

    $this->setReviewers($fields['reviewerGUIDs']);
    $this->setCCPHIDs($fields['ccGUIDs']);
  }
*/

  public function getRevision() {
    return $this->revision;
  }

  public function setReviewers(array $reviewers) {
    $this->reviewers = $reviewers;
    return $this;
  }

  public function setCCPHIDs(array $cc) {
    $this->cc = $cc;
    return $this;
  }

  public function addDiff(DifferentialDiff $diff, $comments) {
    if ($diff->getRevisionID() &&
        $diff->getRevisionID() != $this->getRevision()->getID()) {
      $diff_id = (int)$diff->getID();
      $targ_id = (int)$this->getRevision()->getID();
      $real_id = (int)$diff->getRevisionID();
      throw new Exception(
        "Can not attach diff #{$diff_id} to Revision D{$targ_id}, it is ".
        "already attached to D{$real_id}.");
    }
    $this->diff = $diff;
    $this->comments = $comments;
    return $this;
  }

  protected function getDiff() {
    return $this->diff;
  }

  protected function getComments() {
    return $this->comments;
  }

  protected function getActorPHID() {
    return $this->actorPHID;
  }

  public function isNewRevision() {
    return !$this->getRevision()->getID();
  }

  /**
   * A silent update does not trigger Herald rules or send emails. This is used
   * for auto-amends at commit time.
   */
  public function setSilentUpdate($silent) {
    $this->silentUpdate = $silent;
    return $this;
  }

  public function save() {
    $revision = $this->getRevision();

// TODO
//    $revision->openTransaction();

    $is_new = $this->isNewRevision();
    if ($is_new) {
      // These fields aren't nullable; set them to sensible defaults if they
      // haven't been configured. We're just doing this so we can generate an
      // ID for the revision if we don't have one already.
      $revision->setLineCount(0);
      if ($revision->getStatus() === null) {
        $revision->setStatus(DifferentialRevisionStatus::NEEDS_REVIEW);
      }
      if ($revision->getTitle() === null) {
        $revision->setTitle('Untitled Revision');
      }
      if ($revision->getOwnerPHID() === null) {
        $revision->setOwnerPHID($this->getActorPHID());
      }

      $revision->save();
    }

    $revision->loadRelationships();

    if ($this->reviewers === null) {
      $this->reviewers = $revision->getReviewers();
    }

    if ($this->cc === null) {
      $this->cc = $revision->getCCPHIDs();
    }

    // We're going to build up three dictionaries: $add, $rem, and $stable. The
    // $add dictionary has added reviewers/CCs. The $rem dictionary has
    // reviewers/CCs who have been removed, and the $stable array is
    // reviewers/CCs who haven't changed. We're going to send new reviewers/CCs
    // a different ("welcome") email than we send stable reviewers/CCs.

    $old = array(
      'rev' => array_fill_keys($revision->getReviewers(), true),
      'ccs' => array_fill_keys($revision->getCCPHIDs(), true),
    );

    $diff = $this->getDiff();

    $xscript_header = null;
    $xscript_uri = null;

    $new = array(
      'rev' => array_fill_keys($this->reviewers, true),
      'ccs' => array_fill_keys($this->cc, true),
    );


    $rem_ccs = array();
    if ($diff) {
      $diff->setRevisionID($revision->getID());
      $revision->setLineCount($diff->getLineCount());

// TODO!
//      $revision->setRepositoryID($diff->getRepositoryID());

/*
      $iface = new DifferentialRevisionHeraldable($revision);
      $iface->setExplicitCCs($new['ccs']);
      $iface->setExplicitReviewers($new['rev']);
      $iface->setForbiddenCCs($revision->getForbiddenCCPHIDs());
      $iface->setForbiddenReviewers($revision->getForbiddenReviewers());
      $iface->setDiff($diff);

      $xscript = HeraldEngine::loadAndApplyRules($iface);
      $xscript_uri = $xscript->getURI();
      $xscript_phid = $xscript->getPHID();
      $xscript_header = $xscript->getXHeraldRulesHeader();


      $sub = array(
        'rev' => array(),
        'ccs' => $iface->getCCsAddedByHerald(),
      );
      $rem_ccs = $iface->getCCsRemovedByHerald();
*/
  // TODO!
      $sub = array(
        'rev' => array(),
        'ccs' => array(),
      );


    } else {
      $sub = array(
        'rev' => array(),
        'ccs' => array(),
      );
    }

    // Remove any CCs which are prevented by Herald rules.
    $sub['ccs'] = array_diff_key($sub['ccs'], $rem_ccs);
    $new['ccs'] = array_diff_key($new['ccs'], $rem_ccs);

    $add = array();
    $rem = array();
    $stable = array();
    foreach (array('rev', 'ccs') as $key) {
      $add[$key] = array();
      if ($new[$key] !== null) {
        $add[$key] += array_diff_key($new[$key], $old[$key]);
      }
      $add[$key] += array_diff_key($sub[$key], $old[$key]);

      $combined = $sub[$key];
      if ($new[$key] !== null) {
        $combined += $new[$key];
      }
      $rem[$key] = array_diff_key($old[$key], $combined);

      $stable[$key] = array_diff_key($old[$key], $add[$key] + $rem[$key]);
    }

    self::alterReviewers(
      $revision,
      $this->reviewers,
      array_keys($rem['rev']),
      array_keys($add['rev']),
      $this->actorPHID);

    // Add the owner to the relevant set of users so they get a copy of the
    // email.
    if (!$this->silentUpdate) {
      if ($is_new) {
        $add['rev'][$this->getActorPHID()] = true;
      } else {
        $stable['rev'][$this->getActorPHID()] = true;
      }
    }

    $mail = array();

    $changesets = null;
    $feedback = null;
    if ($diff) {
      $changesets = $diff->loadChangesets();
      // TODO: move to DifferentialFeedbackEditor
      if (!$is_new) {
        // TODO
//        $feedback = $this->createFeedback();
      }
      if ($feedback) {
        $mail[] = id(new DifferentialNewDiffMail(
            $revision,
            $this->getActorPHID(),
            $changesets))
          ->setIsFirstMailAboutRevision($is_new)
          ->setIsFirstMailToRecipients($is_new)
          ->setComments($this->getComments())
          ->setToPHIDs(array_keys($stable['rev']))
          ->setCCPHIDs(array_keys($stable['ccs']));
      }

      // Save the changes we made above.

// TODO
//      $diff->setDescription(substr($this->getComments(), 0, 80));
      $diff->save();

      // An updated diff should require review, as long as it's not committed
      // or accepted. The "accepted" status is "sticky" to encourage courtesy
      // re-diffs after someone accepts with minor changes/suggestions.

      $status = $revision->getStatus();
      if ($status != DifferentialRevisionStatus::COMMITTED &&
          $status != DifferentialRevisionStatus::ACCEPTED) {
        $revision->setStatus(DifferentialRevisionStatus::NEEDS_REVIEW);
      }

    } else {
      $diff = $revision->getActiveDiff();
      if ($diff) {
        $changesets = id(new DifferentialChangeset())->loadAllWithDiff($diff);
      } else {
        $changesets = array();
      }
    }

    $revision->save();

// TODO
//    $revision->saveTransaction();

    $event = array(
      'revision_id' => $revision->getID(),
      'PHID'        => $revision->getPHID(),
      'action'      => $is_new ? 'create' : 'update',
      'actor'       => $this->getActorPHID(),
    );

//  TODO
//    id(new ToolsTimelineEvent('difx', fb_json_encode($event)))->record();

    if ($this->silentUpdate) {
      return;
    }

// TODO
//    $revision->attachReviewers(array_keys($new['rev']));
//    $revision->attachCCPHIDs(array_keys($new['ccs']));

    if ($add['ccs'] || $rem['ccs']) {
      foreach (array_keys($add['ccs']) as $id) {
        if (empty($new['ccs'][$id])) {
          $reason_phid = 'TODO';//$xscript_phid;
        } else {
          $reason_phid = $this->getActorPHID();
        }
        self::addCCPHID($revision, $id, $reason_phid);
      }
      foreach (array_keys($rem['ccs']) as $id) {
        if (empty($new['ccs'][$id])) {
          $reason_phid = $this->getActorPHID();
        } else {
          $reason_phid = 'TODO';//$xscript_phid;
        }
        self::removeCCPHID($revision, $id, $reason_phid);
      }
    }

    if ($add['rev']) {
      $message = id(new DifferentialNewDiffMail(
          $revision,
          $this->getActorPHID(),
          $changesets))
        ->setIsFirstMailAboutRevision($is_new)
        ->setIsFirstMailToRecipients(true)
        ->setToPHIDs(array_keys($add['rev']));

      if ($is_new) {
        // The first time we send an email about a revision, put the CCs in
        // the "CC:" field of the same "Review Requested" email that reviewers
        // get, so you don't get two initial emails if you're on a list that
        // is CC'd.
        $message->setCCPHIDs(array_keys($add['ccs']));
      }

      $mail[] = $message;
    }

    // If you were added as a reviewer and a CC, just give you the reviewer
    // email. We could go to greater lengths to prevent this, but there's
    // bunch of stuff with list subscriptions anyway. You can still get two
    // emails, but only if a revision is updated and you are added as a reviewer
    // at the same time a list you are on is added as a CC, which is rare and
    // reasonable.
    $add['ccs'] = array_diff_key($add['ccs'], $add['rev']);

    if (!$is_new && $add['ccs']) {
      $mail[] = id(new DifferentialCCWelcomeMail(
          $revision,
          $this->getActorPHID(),
          $changesets))
        ->setIsFirstMailToRecipients(true)
        ->setToPHIDs(array_keys($add['ccs']));
    }

    foreach ($mail as $message) {
// TODO
//      $message->setHeraldTranscriptURI($xscript_uri);
//      $message->setXHeraldRulesHeader($xscript_header);
      $message->send();
    }
  }

  public function addCCPHID(
    DifferentialRevision $revision,
    $phid,
    $reason_phid) {
    self::alterCCPHID($revision, $phid, true, $reason_phid);
  }

  public function removeCCPHID(
    DifferentialRevision $revision,
    $phid,
    $reason_phid) {
    self::alterCCPHID($revision, $phid, false, $reason_phid);
  }

  protected static function alterCCPHID(
    DifferentialRevision $revision,
    $phid,
    $add,
    $reason_phid) {
/*
    $relationship = new DifferentialRelationship();
    $relationship->setRevisionID($revision->getID());
    $relationship->setRelation(DifferentialRelationship::RELATION_SUBSCRIBED);
    $relationship->setRelatedPHID($phid);
    $relationship->setForbidden(!$add);
    $relationship->setReasonPHID($reason_phid);
    $relationship->replace();
*/
  }


  public static function alterReviewers(
    DifferentialRevision $revision,
    array $stable_phids,
    array $rem_phids,
    array $add_phids,
    $reason_phid) {

    $rem_map = array_fill_keys($rem_phids, true);
    $add_map = array_fill_keys($add_phids, true);

    $seq_map = array_values($stable_phids);
    $seq_map = array_flip($seq_map);
    foreach ($rem_map as $phid => $ignored) {
      if (!isset($seq_map[$phid])) {
        $seq_map[$phid] = count($seq_map);
      }
    }
    foreach ($add_map as $phid => $ignored) {
      if (!isset($seq_map[$phid])) {
        $seq_map[$phid] = count($seq_map);
      }
    }

    $raw = $revision->getRawRelations(DifferentialRevision::RELATION_REVIEWER);
    $raw = ipull($raw, 'objectPHID');

    $sequence = count($seq_map);
    foreach ($raw as $phid => $relation) {
      if (isset($seq_map[$phid])) {
        $raw[$phid]['sequence'] = $seq_map[$phid];
      } else {
        $raw[$phid]['sequence'] = $sequence++;
      }
    }
    $raw = isort($raw, 'sequence');

    foreach ($raw as $phid => $relation) {
      if (isset($rem_map[$phid])) {
        $relation['forbidden'] = true;
        $relation['reasonPHID'] = $reason_phid;
      } else if (isset($add_map[$phid])) {
        $relation['forbidden'] = false;
        $relation['reasonPHID'] = $reason_phid;
      }
    }

    foreach ($add_phids as $add) {
      $raw[] = array(
        'objectPHID'  => $add,
        'forbidden'   => false,
        'sequence'    => idx($seq_map, $add, $sequence++),
        'reasonPHID'  => $reason_phid,
      );
    }

    $conn_w = $revision->establishConnection('w');

    $sql = array();
    foreach ($raw as $relation) {
      $sql[] = qsprintf(
        $conn_w,
        '(%d, %s, %s, %d, %d, %s)',
        $revision->getID(),
        DifferentialRevision::RELATION_REVIEWER,
        $relation['objectPHID'],
        $relation['forbidden'],
        $relation['sequence'],
        $relation['reasonPHID']);
    }

    $conn_w->openTransaction();
      queryfx(
        $conn_w,
        'DELETE FROM %T WHERE revisionID = %d AND relation = %s',
        DifferentialRevision::RELATIONSHIP_TABLE,
        $revision->getID(),
        DifferentialRevision::RELATION_REVIEWER);
      if ($sql) {
        queryfx(
          $conn_w,
          'INSERT INTO %T
            (revisionID, relation, objectPHID, forbidden, sequence, reasonPHID)
          VALUES %Q',
          DifferentialRevision::RELATIONSHIP_TABLE,
          implode(', ', $sql));
      }
    $conn_w->saveTransaction();
  }

/*
  protected function createFeedback() {
    $revision = $this->getRevision();
    $feedback = id(new DifferentialFeedback())
      ->setUserID($this->getActorPHID())
      ->setRevision($revision)
      ->setContent($this->getComments())
      ->setAction('update');

    $feedback->save();

    return $feedback;
  }
*/

}

