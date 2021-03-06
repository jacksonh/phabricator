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

final class PhabricatorRequestOverseer {

  public function didStartup() {
    $this->detectPostMaxSizeTriggered();
  }

  /**
   * Detect if this request has had its POST data stripped by exceeding the
   * 'post_max_size' PHP configuration limit.
   *
   * PHP has a setting called 'post_max_size'. If a POST request arrives with
   * a body larger than the limit, PHP doesn't generate $_POST but processes
   * the request anyway, and provides no formal way to detect that this
   * happened.
   *
   * We can still read the entire body out of `php://input`. However, this
   * stream can't be rewound, and according to the documentation isn't available
   * for "multipart/form-data" (on nginx + php-fpm it appears that it is
   * available, though, at least) so any attempt to generate $_POST would create
   * side effects and be fragile.
   */
  private function detectPostMaxSizeTriggered() {
    // If this wasn't a POST, we're fine.
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      return;
    }

    // If there's POST data, clearly we're in good shape.
    if ($_POST) {
      return;
    }

    // PHP generates $_POST only for two content types. This routing happens
    // in `main/php_content_types.c` in PHP. Normally, all forms use one of
    // these content types, but some requests may not -- for example, Firefox
    // submits files sent over HTML5 XMLHTTPRequest APIs with the Content-Type
    // of the file itself. If we don't have a recognized content type, we
    // don't need $_POST.
    //
    // NOTE: We use strncmp() because the actual content type may be something
    // like "multipart/form-data; boundary=...".
    //
    // NOTE: Chrome sometimes omits this header, see some discussion in T1762
    // and http://code.google.com/p/chromium/issues/detail?id=6800
    $content_type = idx($_SERVER, 'CONTENT_TYPE', '');

    $parsed_types = array(
      'application/x-www-form-urlencoded',
      'multipart/form-data',
    );

    $is_parsed_type = false;
    foreach ($parsed_types as $parsed_type) {
      if (strncmp($content_type, $parsed_type, strlen($parsed_type)) === 0) {
        $is_parsed_type = true;
        break;
      }
    }

    if (!$is_parsed_type) {
      return;
    }

    // Check for 'Content-Length'. If there's no data, we don't expect $_POST
    // to exist.
    $length = (int)$_SERVER['CONTENT_LENGTH'];
    if (!$length) {
      return;
    }

    // Time to fatal: we know this was a POST with data that should have been
    // populated into $_POST, but it wasn't.

    $config = ini_get('post_max_size');
    $this->fatal(
      "As received by the server, this request had a nonzero content length ".
      "but no POST data.\n\n".
      "Normally, this indicates that it exceeds the 'post_max_size' setting ".
      "in the PHP configuration on the server. Increase the 'post_max_size' ".
      "setting or reduce the size of the request.\n\n".
      "Request size according to 'Content-Length' was '{$length}', ".
      "'post_max_size' is set to '{$config}'.");
  }

  /**
   * Defined in webroot/index.php.
   * TODO: Move here.
   *
   * @phutil-external-symbol function phabricator_fatal
   */
  public function fatal($message) {
    phabricator_fatal('FATAL ERROR: '.$message);
  }

}
