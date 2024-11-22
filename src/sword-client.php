<?php

/**
 * Importe en SWORD tous les fichiers XML d'un répertoire
 */

do {
    $dir = getParam("Chemin vers le répertoire des fichiers tei");
} while(! is_dir($dir));

//Chemin vers le répertoire contenant les fichiers XML-TEI
define('DIR', $dir . '/');
//Environnement de dépôt 
define('SERVER', getParam("Environnement", true, ['preprod', 'production'], 'production'));
//Code du portail de dépôt (hal, inria, univ-lorraine)
define('PORTAIL', getParam("Portail", true, [], 'hal'));
define('SWORD_SERVER', 'https://api' . (SERVER != 'production' ? '-preprod': '') . '.archives-ouvertes.fr/sword/' . PORTAIL);
//Login du compte de dépôt
define('LOGIN', getParam("Votre login", true ));
//Password du compte de dépôt
define('PWD', getPassword("Votre mot de passe", true ));

define('LOGFILE',  $dir . 'import.log');


$str = 'FICHIER' . "\t\t" . 'CODE' . "\t" . 'IDENTIFIANT' . "\t" . 'PASSWORD' . "\t" . 'ERROR' ;
println('FICHIER' . "\t\t" . 'CODE' . "\t" . 'IDENTIFIANT' . "\t" . 'PASSWORD' . "\t" . 'ERROR' );
file_put_contents(LOGFILE, $str . "\n", FILE_APPEND);

$sword = new SwordUpload();
foreach (scandir(DIR) as $file){
    if ($file == '.DS_Store') {
        continue;
    }
    $filePath = DIR . $file;
    if (is_file($filePath)) {
        $sword->setFilePath($filePath);
        $result = $sword->post();

        $str = $file . "\t" . implode("\t", $result);
        println($str);

        file_put_contents(LOGFILE, $str . "\n", FILE_APPEND);
    }
}

class SwordUpload
{
    protected $_filePath = '';

    public function __construct($filePath = '')
    {
        if ($filePath != ''){
            $this->setFilePath($filePath);
        }
    }

    public function setFilePath($filePath)
    {
        $this->_filePath = $filePath;
    }

    public function getFilePath()
    {
        return $this->_filePath;
    }

    public function getFileContent()
    {
        return file_get_contents($this->getFilePath());
    }

    public function post()
    {
        $curl = curl_init(SWORD_SERVER);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 100);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Packaging: ' . 'http://purl.org/net/sword-types/AOfr', 'Content-Type:text/xml']);
        curl_setopt($curl, CURLOPT_USERPWD, LOGIN . ':' . PWD);
        curl_setopt($curl, CURLOPT_USERAGENT, 'HAL script import multiple');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $this->getFileContent());

        $return = curl_exec($curl);

        $result = [
            'code'          =>  curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'identifiant'   =>  "\t",
            'password'      =>  "\t",
            'error'         =>  "\t",
        ];
        try {
            $entry = @new SimpleXMLElement($return);
            $entry->registerXPathNamespace('hal', 'http://hal.archives-ouvertes.fr/');
            $entry->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            $entry->registerXPathNamespace('sword', 'http://purl.org/net/sword/');
            if (in_array($result['code'], array(200, 201, 202, 302))) {
                $result['identifiant'] = (string)$entry->id;
                $hal = $entry->children('hal', true);
                $result['password'] = (string)$hal->password;
            } else {
                $result['error'] = (string)$entry->xpath('/sword:error/sword:verboseDescription')[0];
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;

    }
}


function getParam($text, $required = true, $values = [], $default = '')
{
    if (count($values)) {
        $tmp = [];
        foreach ($values as $v) {
            $tmp[] = ($v == $default) ? $v . '[default]' : $v;
        }
        $text .= '(' . implode(', ', $tmp) . ')';
    } else if ($default) {
        $text .= '(' . $default. '[default]' . ')';
    }

    while (true) {
        print($text . ' : ');

        $res = trim(fgets(STDIN));

        if (count($values) && $default && !in_array($res, $values)) {
            $res = $default;
        } else if ($default && $res == '') {
            $res = $default;
        }

        if (!$required || $res != '') {
            break;
        }

    }
    return $res;
}

function getPassword($text, $stars = false)
{
    print($text . ' : ');
    // Get current style
    $oldStyle = shell_exec('stty -g');

    if ($stars === false) {
        shell_exec('stty -echo');
        $password = rtrim(fgets(STDIN), "\n");
    } else {
        shell_exec('stty -icanon -echo min 1 time 0');

        $password = '';
        while (true) {
            $char = fgetc(STDIN);

            if ($char === "\n") {
                break;
            } else if (ord($char) === 127) {
                if (strlen($password) > 0) {
                    fwrite(STDOUT, "\x08 \x08");
                    $password = substr($password, 0, -1);
                }
            } else {
                fwrite(STDOUT, "*");
                $password .= $char;
            }
        }
    }

    // Reset old style
    shell_exec('stty ' . $oldStyle);

    // Return the password
    return $password;
}

function println($s = '')
{
    print $s . PHP_EOL;
}

