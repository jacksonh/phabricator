#!/usr/bin/env php
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

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/scripts/__init_script__.php';

$args = new PhutilArgumentParser($argv);
$args->setSynopsis(<<<EOHELP
**reparse.php** __what__ __which_parts__ [--trace] [--force]

Rerun the Diffusion parser on specific commits and repositories. Mostly
useful for debugging changes to Diffusion.
EOHELP
);

$args->parseStandardArguments();
$args->parse(
  array(
    // what
    array(
      'name'     => 'revision',
      'wildcard' => true,
    ),
    array(
      'name'     => 'all',
      'param'    => 'callsign or phid',
      'help'     => 'Reparse all commits in the specified repository. This '.
                    'mode queues parsers into the task queue; you must run '.
                    'taskmasters to actually do the parses. Use with '.
                    '__--force-local__ to run the tasks locally instead of '.
                    'with taskmasters.',
    ),
    array(
      'name'     => 'min-date',
      'param'    => 'date',
      'help'     => 'When used with __--all__, this will restrict to '.
                    'reparsing only the commits that are newer than __date__.',
    ),
    // which parts
    array(
      'name'     => 'message',
      'help'     => 'Reparse commit messages.',
    ),
    array(
      'name'     => 'change',
      'help'     => 'Reparse changes.',
    ),
    array(
      'name'     => 'herald',
      'help'     => 'Reevaluate Herald rules (may send huge amounts of email!)',
    ),
    array(
      'name'     => 'owners',
      'help'     => 'Reevaluate related commits for owners packages (may '.
                    'delete existing relationship entries between your '.
                    'package and some old commits!)',
    ),
    // misc options
    array(
      'name'     => 'force',
      'short'    => 'f',
      'help'     => 'Act noninteractively, without prompting.',
    ),
    array(
      'name'     => 'force-local',
      'help'     => 'Only used with __--all__, use this to run the tasks '.
                    'locally instead of deferring them to taskmaster daemons.',
    ),
  ));

$all_from_repo = $args->getArg('all');
$reparse_message = $args->getArg('message');
$reparse_change = $args->getArg('change');
$reparse_herald = $args->getArg('herald');
$reparse_owners = $args->getArg('owners');
$reparse_what = $args->getArg('revision');
$force = $args->getArg('force');
$force_local = $args->getArg('force-local');
$min_date = $args->getArg('min-date');

if (!$all_from_repo && !$reparse_what) {
  usage("Specify a commit or repository to reparse.");
}

if (!$reparse_message && !$reparse_change && !$reparse_herald &&
    !$reparse_owners) {
  usage("Specify what information to reparse with --message, --change,  ".
        "--herald, and/or --owners");
}
if ($reparse_owners && !$force) {
  echo phutil_console_wrap(
    "You are about to recreate the relationship entries between the commits ".
    "and the packages they touch. This might delete some existing ".
    "relationship entries for some old commits.");

  if (!phutil_console_confirm('Are you ready to continue?')) {
    echo "Cancelled.\n";
    exit(1);
  }
}

$commits = array();
if ($all_from_repo) {
  $repository = id(new PhabricatorRepository())->loadOneWhere(
    'callsign = %s OR phid = %s',
    $all_from_repo,
    $all_from_repo);
  if (!$repository) {
    throw new Exception("Unknown repository {$all_from_repo}!");
  }
  $constraint = '';
  if ($min_date) {
    $table = new PhabricatorRepositoryCommit();
    $conn_r = $table->establishConnection('r');
    $constraint = qsprintf(
      $conn_r,
      'AND epoch > unix_timestamp(%s)',
      $min_date);
  }
  $commits = id(new PhabricatorRepositoryCommit())->loadAllWhere(
    'repositoryID = %d %Q',
    $repository->getID(),
    $constraint);
  if (!$commits) {
    throw new Exception("No commits have been discovered in that repository!");
  }
  $callsign = $repository->getCallsign();
} else {
  $commits = array();
  foreach ($reparse_what as $identifier) {
    $matches = null;
    if (!preg_match('/r([A-Z]+)([a-z0-9]+)/', $identifier, $matches)) {
      throw new Exception("Can't parse commit identifier!");
    }
    $callsign = $matches[1];
    $commit_identifier = $matches[2];
    $repository = id(new PhabricatorRepository())->loadOneWhere(
      'callsign = %s',
      $callsign);
    if (!$repository) {
      throw new Exception("No repository with callsign '{$callsign}'!");
    }
    $commit = id(new PhabricatorRepositoryCommit())->loadOneWhere(
      'repositoryID = %d AND commitIdentifier = %s',
      $repository->getID(),
      $commit_identifier);
    if (!$commit) {
      throw new Exception(
        "No matching commit '{$commit_identifier}' in repository ".
        "'{$callsign}'. (For git and mercurial repositories, you must specify ".
        "the entire commit hash.)");
    }
    $commits[] = $commit;
  }
}

if ($all_from_repo && !$force_local) {
  echo phutil_console_format(
    '**NOTE**: This script will queue tasks to reparse the data. Once the '.
    'tasks have been queued, you need to run Taskmaster daemons to execute '.
    'them.');
  echo "\n\n";
  echo "QUEUEING TASKS (".number_format(count($commits))." Commits):\n";
}

$tasks = array();
foreach ($commits as $commit) {
  $classes = array();
  switch ($repository->getVersionControlSystem()) {
    case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
      if ($reparse_message) {
        $classes[] = 'PhabricatorRepositoryGitCommitMessageParserWorker';
      }
      if ($reparse_change) {
        $classes[] = 'PhabricatorRepositoryGitCommitChangeParserWorker';
      }
      break;
    case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
      if ($reparse_message) {
        $classes[] = 'PhabricatorRepositoryMercurialCommitMessageParserWorker';
      }
      if ($reparse_change) {
        $classes[] = 'PhabricatorRepositoryMercurialCommitChangeParserWorker';
      }
      break;
    case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
      if ($reparse_message) {
        $classes[] = 'PhabricatorRepositorySvnCommitMessageParserWorker';
      }
      if ($reparse_change) {
        $classes[] = 'PhabricatorRepositorySvnCommitChangeParserWorker';
      }
      break;
  }

  if ($reparse_herald) {
    $classes[] = 'PhabricatorRepositoryCommitHeraldWorker';
  }

  if ($reparse_owners) {
    $classes[] = 'PhabricatorRepositoryCommitOwnersWorker';
  }

  $spec = array(
    'commitID'  => $commit->getID(),
    'only'      => true,
  );

  if ($all_from_repo && !$force_local) {
    foreach ($classes as $class) {
      $task = new PhabricatorWorkerTask();
      $task->setTaskClass($class);
      $task->setData($spec);
      $task->save();

      $commit_name = 'r'.$callsign.$commit->getCommitIdentifier();
      echo "  Queued '{$class}' for commit '{$commit_name}'.\n";
    }
  } else {
    foreach ($classes as $class) {
      $worker = newv($class, array($spec));
      echo "Running '{$class}'...\n";
      $worker->doWork();
    }
  }
}

echo "\nDone.\n";

function usage($message) {
  echo phutil_console_format(
    '**Usage Exception:** '.$message."\n");
  exit(1);
}
