<?php
/*
 * phpSFTP.php
 *
 * Copyright (C) 2015 KISS IT Consulting <http://www.kissitconsulting.com/>
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above
 *    copyright notice, this list of conditions and the following
 *    disclaimer in the documentation and/or other materials
 *    provided with the distribution.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL ANY
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 * OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
class phpSFTP {
    private $ftp = null;
    private $host = null;
    private $user = null;
    private $password = null;
    private $implicit = null;
    private $port = null;
    private $pasv = null;
    private $binary = null;
    private $curlopts = null;

    /*
     * Here is our constructor, params should be self explanatory.  A few notes:
     *  1. Probably most important to note is the $implicit flag.  Set to true 
     *      to use "Implicit" SFTP (via cURL).  Set to false to use "Explicit" SFTP (via PHP ftp_ssl_connect)
     *  2. PASV is currently the only supported mode for Implicit SFTP
     */
    public function __construct($host, $user, $password, $port = 21, $implicit = false, $pasv = true, $binary = true) {
            $this->host = (string)$host;
            $this->user = (string)$user;
            $this->password = (string)$password;
            $this->implicit = (bool)$implicit;
            $this->port = (int)$port;
            $this->pasv = (bool)$pasv;
            $this->binary = (bool)$binary;
            $this->connect();
    }

    // We define the destructor to make sure we close our connection if not already done
    public function __destruct() {
        $this->close();
    }

    // Method to close our connection if it exists
    public function close() {
        if(is_resource($this->ftp)) {
            if($this->implicit) {
                curl_close($this->ftp);
            } else {
                ftp_close($this->ftp);
            }
        }
        $this->ftp = null;
    }

    // Method to connect to the SFTP using the provided details.  Exception raised on failure.
    public function connect() {
        if($this->implicit) {
            // We are using Implicit SFTP so must use cURL.  First set some default CURLOPT's that should normally stay consistent
            $this->curlopts[CURLOPT_USERPWD] = "{$this->user}:{$this->password}";
            $this->curlopts[CURLOPT_SSL_VERIFYPEER] = false;
            $this->curlopts[CURLOPT_SSL_VERIFYHOST] = false;
            $this->curlopts[CURLOPT_FTP_SSL] = CURLFTPSSL_TRY;
            $this->curlopts[CURLOPT_FTPSSLAUTH] = CURLFTPAUTH_TLS;
            $this->curlopts[CURLOPT_RETURNTRANSFER] = true;
            
            // Now lets run a test to make sure we can connect
            try {
                $this->dir("/");
            } catch(Exception $e) {
                throw new Exception("Implicit secure FTP login failed: " . $e->getMessage());
            }

        } else {
            // We are using Explicit SFTP so use the PHP native functions
            $this->ftp = ftp_ssl_connect($this->host, $this->port);
            if($this->ftp) {
                if(ftp_login($this->ftp, $this->user, $this->password)) {
                    if($this->pasv === true && !ftp_pasv($this->ftp, true)) {
                        $this->close();
                        throw new Exception("Explicit secure FTP PASV failed");
                    }
                } else {
                    $this->close();
                    throw new Exception("Explicit secure FTP login failed");
                }
            } else {
                throw new Exception("Explicit secure FTP connection failed");
            }
        }
    }

    // Method to get a file from the SFTP server.
    public function get($local, $remote) {
        $return = false;
        if(!empty($remote) && !empty($local)) {
            if($this->implicit) {
                // Implicit SFTP
                $fp = fopen($local, "w");
                if($fp) {
                    $opts = $this->getCurlXferMode();
                    $opts[CURLOPT_URL] = $this->getCurlURL($remote);
                    $opts[CURLOPT_FILE] = $fp;
                    $return = $this->callCurl($opts);
                }
            } else {
                // Explicit SFTP
                $return = ftp_get($this->ftp, $local, $remote, $this->getMode());
            }
        }
        return $return;
    }

    // Method to put a file to the SFTP server.
    public function put($local, $remote) {
        $return = false;
        if(!empty($remote) && !empty($local)) {
            if($this->implicit) {
                // Implicit SFTP
                $fp = fopen($local, "r");
                if($fp) {
                    $opts = $this->getCurlXferMode();
                    $opts[CURLOPT_URL] = $this->getCurlURL($remote);
                    $opts[CURLOPT_INFILE] = $fp;
                    $opts[CURLOPT_UPLOAD] = true;
                    $return = $this->callCurl($opts);
                }
            } else {
                // Explicit SFTP
                 $return = ftp_put($this->ftp, $remote, $local, $this->getMode());
            }
        }
        return $return;
    }

    // Method to rename a file on the SFTP server.
    public function rename($old, $new) {
        $return = false;
        if(!empty($old) && !empty($new)) {
            if($this->implicit) {
                // Implicit SFTP
                $opts = array();
                $opts[CURLOPT_URL] = $this->getCurlURL();
                $opts[CURLOPT_POSTQUOTE] = array("RNFR $old", "RNTO $new");
                $return = $this->callCurl($opts);
            } else {
                // Explicit SFTP
                 $return = ftp_rename($this->ftp, $old, $new);
            }
            
        }
        return $return;
    }

    // Method to delete a file on the SFTP server.
    public function delete($file) {
        $return = false;
        if(!empty($file) && !empty($new)) {
            if($this->implicit) {
                // Implicit SFTP
                $opts = array();
                $opts[CURLOPT_URL] = $this->getCurlURL();
                $opts[CURLOPT_QUOTE] = array("DELE $file");
                $return = $this->callCurl($opts);
            } else {
                // Explicit SFTP
                 $return = ftp_delete($this->ftp, $file);
            }
            
        }
        return $return;
    }

    // Method to get a list of files from the passed in directory
    public function dir($path) {
        $return = false;
        if(!empty($path)) {
            if(!preg_match('/\/$/', $path)) {
                $path = "{$path}/";
            }
            if($this->implicit) {
                // Implicit SFTP
                $opts = array();
                $opts[CURLOPT_URL] = $this->getCurlURL($path);
                $opts[CURLOPT_FTPLISTONLY] = true;
                $return = $this->callCurl($opts);
                if(!empty($return)) {
                    $return = preg_split("/\r\n|\n|\r/", $return, null, PREG_SPLIT_NO_EMPTY);
                }
            } else {
                // Explicit SFTP
                $return = ftp_nlist($this->ftp, $path);
            }
        }

        return $return;
    }

    // Private method to set the curl options for the transfer mode
    private function getCurlXferMode() {
        if($this->binary) {
            return array(CURLOPT_BINARYTRANSFER => true);
            return array(CURLOPT_TRANSFERTEXT => false);
        } else {
            return array(CURLOPT_TRANSFERTEXT => true);
        }
    }

    // Private method to get the current mode for ftp_ functions
    private function getMode() {
        if($this->binary) {
            return FTP_BINARY;
        } else {
            return FTP_ASCII;
        }
    }

    // Private method to build a CUROPT_URL from our options
    private function getCurlURL($path = '') {
        if(!empty($path)) {
            if(!preg_match('/^\//', $path)) {
                // Force a fully qualified path to avoid issues
                $path = "/{$path}";
            }
        }
        return "ftps://{$this->host}:{$this->port}{$path}";
    }

    // Private method to make a cURL SFTP call based on the options passed in combined w/ the default ones.
    private function callCurl($call_opts) {
        $return = null;

        // Combine our options, allowing those passed in to override defaults
        $opts = $this->curlopts;
        foreach($call_opts as $key => $value) {
            $opts[$key] = $value;
        }

        // Init our curl handle and try to run the operation.  If we get an error throw it as an exception
        $this->ftp = curl_init();
        if(curl_setopt_array($this->ftp, $opts)) {
            $return = curl_exec($this->ftp);
            $error = curl_error($this->ftp);
            if(!empty($error)) {
                throw new Exception($error);
            }
        }
        curl_close($this->ftp);
        return $return;
    }
}
?>