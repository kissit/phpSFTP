# phpSFTP
PHP SFTP class that supports both implicit and explicit SFTP

I created this class due to the need to support both implicit and explicit SFTP.  Implicit SFTP connections are handled via cURL while explicit connections are handled via the standard PHP ftp_ functions.  Only the functions that I needed at the time are supported however others could easily be added.

## Instructions
* Download the class, no need for any fancy dependency managers or whatnot.
* Include it in your code
```
require_once 'include/phpSFTP.php';
```

## Usage
* Instantiate your object.  This will also validate your connection works.
```
$implicit = true;
try {
    $ftp = new phpSFTP($hostname, $username, $password, $port, $implicit);
} catch(Exception $e) {
    echo "Failed connecting to $identifier via SFTP.  " . $e->getMessage()";
    exit;
}
```
* List a directory
```
$list = $ftp->dir('/');
foreach($list as $file) {
    echo "Filename: $file\n";
}
```
* Download a file
```
if($ftp->get("/tmp/local_file.txt", "/xyz/remote_file.txt")) {
    echo "File downloaded as /tmp/local_file.txt";
} else {
    echo "File downloaded failed";
}
```
* Upload a file
```
if($ftp->put("/tmp/local_file.txt", "/xyz/remote_file.txt")) {
    echo "File uploaded as /xyz/remote_file.txt";
} else {
    echo "File upload failed";
}
```
* Rename a file
```
if($ftp->rename("/xyz/remote_file.txt", "/xyz/remote_file_renamed.txt")) {
    echo "File /xyz/remote_file.txt as /xyz/remote_file_renamed.txt";
} else {
    echo "File rename failed";
}
```
* Delete a file
```
if($ftp->delete("/xyz/remote_file_renamed.txt")) {
    echo "File /xyz/remote_file_renamed.txt deleted";
} else {
    echo "File delete failed";
}
```
* Close the connection
```
$ftp->close();
```