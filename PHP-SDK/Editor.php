<?php

namespace Simbigo\Verstka;

class Editor
{
    const ERROR_CODE_FAIL = 0;
    const ERROR_CODE_NONE = 1;
    /**
     * @var string
     */
    private $apiKey;
    /**
     * @var string
     */
    private $callbackUrl;
    /**
     * @var bool
     */
    private $debug = false;
    /**
     * @var string
     */
    private $failMessage = 'Callback fail';
    /**
     * @var bool
     */
    private $forceLackingImages = false;
    /**
     * @var string
     */
    private $hostname;
    /**
     * @var int
     */
    private $maxRequestsPerBatch = 99;
    /**
     * @var string
     */
    private $secret;
    /**
     * @var string
     */
    private $successMessage = 'Save successfully';
    /**
     * @var string
     */
    private $tempDirectory;
    /**
     * @var string
     */
    private $webRoot;
    /**
     * @var string
     */
    protected $apiUri = 'https://verstka.io/api';

    /**
     * Editor constructor.
     *
     * @param $apiKey
     * @param $secret
     * @param $callbackUrl
     */
    public function __construct($apiKey, $secret, $callbackUrl)
    {
        $this->setApiKey($apiKey);
        $this->setSecret($secret);
        $this->setCallbackUrl($callbackUrl);
    }

    /**
     * @return string
     */
    protected function getCurrentUrl()
    {
        $scheme = $this->isSecureConnection() ? 'https' : 'http';
        return $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    /**
     * @return bool
     */
    protected function isSecureConnection()
    {
        return
            isset($_SERVER['HTTPS']) && (strcasecmp($_SERVER['HTTPS'], 'on') === 0 || (int)$_SERVER['HTTPS'] === 1)
            ||
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0;
    }

    /**
     * @param $json
     * @param bool $asArray
     * @return mixed
     * @throws Exception
     */
    protected function jsonDecode($json, $asArray = true)
    {
        $result = json_decode($json, $asArray);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(json_last_error_msg());
        }
        return $result;
    }

    /**
     * @param $source
     * @param bool $forceObject
     * @return string
     */
    protected function jsonEncode($source, $forceObject = true)
    {
        if ($forceObject && $source === []) {
            return '{}';
        }
        return json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param $code
     * @param $message
     * @param array $data
     * @return string
     */
    protected function makeResponse($code, $message, array $data = [])
    {
        return json_encode([
            'rc' => $code,
            'rm' => $message,
            'data' => $data
        ], JSON_NUMERIC_CHECK);
    }

    /**
     * @param $request
     * @return string
     */
    protected function makeSign($request)
    {
        $order = ['session_id', 'user_id', 'material_id', 'download_url'];
        $parts = [$this->secret];
        foreach ($order as $field) {
            if (isset($request[$field])) {
                $parts[] = $request[$field];
            }
        }
        return md5(implode('', $parts));
    }

    /**
     * @param $requests
     * @param array $params
     * @return mixed
     * @throws \Simbigo\Verstka\Exception
     */
    protected function multiRequest($requests, array $params = [])
    {
        $queues = [];
        $maxRequestsPerBatch = $this->getMaxRequestsPerBatch();
        while (count($requests) > $maxRequestsPerBatch) {
            $queues[] = array_splice($requests, 0, $maxRequestsPerBatch);
        }
        $queues[] = array_splice($requests, 0, $maxRequestsPerBatch);

        /** @noinspection SuspiciousLoopInspection */
        foreach ($queues as $queueId => $requests) {

            $mh = curl_multi_init();
            foreach ($requests as $confId => $conf) {
                $params['return_handler'] = true;
                $conf = array_merge($params, $conf);
                $requests[$confId]['curl'] = $this->requestUri($conf['url'], $conf);
                curl_multi_add_handle($mh, $requests[$confId]['curl']);
            }

            $mrc = CURLM_OK;
            $active = true;
            while ($active && $mrc === CURLM_OK) {
                if (curl_multi_select($mh) === -1) {
                    usleep(100);
                }
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($requests as $confId => $conf) {
                $requests[$confId]['result'] = curl_multi_getcontent($requests[$confId]['curl']);
                $error = curl_error($requests[$confId]['curl']);
                if (!empty($error)) {
                    $requests[$confId]['result'] = $error;
                    if (!empty($requests[$confId]['download_to'])) {
                        unlink($requests[$confId]['download_to']);
                    }
                }
                curl_multi_remove_handle($mh, $requests[$confId]['curl']);
                unset($requests[$confId]['curl']);
            }
            curl_multi_close($mh);
            $queues[$queueId] = $requests;
        }

        $requests = [];
        foreach ($queues as $conf) {
            foreach ($conf as $buff) {
                $requests[] = $buff;
            }
        }

        return $requests;
    }

    /**
     * @param $articleId
     * @return int
     */
    protected function normalizeArticleId($articleId)
    {
        //it is strongly recommended to declare for save reasons (if You will have more than one article)
        if (empty($articleId)) {
            $articleId = 1;
        }
        return $articleId;
    }

    /**
     * @param $url
     * @return string
     */
    protected function normalizeCallbackUrl($url)
    {
        return empty($url) ? $this->getCurrentUrl() : $url;
    }

    /**
     * @param $path
     * @return bool|string
     */
    protected function normalizePath($path)
    {
        return rtrim(realpath($path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param $userId
     * @return mixed
     */
    protected function normalizeUserId($userId)
    {
        if (empty($userId)) { //it is strongly recommended to declare for safety and working of article locks
            if (!empty($_SERVER['PHP_AUTH_USER'])) {
                $userId = $_SERVER['PHP_AUTH_USER'];
            } elseif (!empty($_SERVER['REMOTE_USER'])) {
                $userId = $_SERVER['REMOTE_USER'];
            } else {
                $userId = $_SERVER['REMOTE_ADDR'];
            }
        }
        return $userId;
    }

    /**
     * @param $url
     * @param array $params
     * @return array|mixed
     * @throws \Simbigo\Verstka\Exception
     */
    protected function requestUri($url, array $params = [])
    {
        if (!function_exists('curl_version')) {
            throw new Exception('There no lib Curl enabled see https://secure.php.net/curl');
        }

        if (!empty($params['download_to'])) {
            set_time_limit(0);
            $fp = fopen($params['download_to'], 'wb+');
        }

        $ch = curl_init();

        if (!empty($params['upload_from'])) {
            set_time_limit(0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
            $params['upload_from'] = (array)$params['upload_from'];
            foreach ($params['upload_from'] as $localFile) {
                $localFile = pathinfo($localFile);
                $params['POST'][$localFile['basename']] = '@' . $localFile['dirname'] . DIRECTORY_SEPARATOR . $localFile['basename'];
            }
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 99);
        if (!empty($params['auth_user']) && !empty($params['auth_pw'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $params['auth_user'] . ':' . $params['auth_pw']);
        }

        if (!empty($fp)) {
            curl_setopt($ch, CURLOPT_FILE, $fp);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        }

        if (!empty($params['GET'])) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params['GET']));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        if (!empty($params['POST'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params['POST']);
        }

        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);

        if (!empty($params['return_handler'])) {
            return $ch;
        }

        $result = curl_exec($ch);

        if (0 === curl_errno($ch)) {
            curl_close($ch);
            if (!empty($fp)) {
                fclose($fp);
            }
            return $result;
        }

        $result = [
            'request' => ['url' => $url, 'params' => $params],
            'error' => ['code' => curl_errno($ch), 'error' => curl_error($ch)]
        ];
        curl_close($ch);
        if (!empty($fp)) {
            fclose($fp);
        }
        return $result;
    }

    /**
     * @return mixed
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @return string
     */
    public function getApiUri()
    {
        return $this->apiUri;
    }

    /**
     * @return mixed
     */
    public function getCallbackUrl()
    {
        return $this->callbackUrl;
    }

    /**
     * @return string
     */
    public function getClientScripts()
    {
        return '<style>[data-vms-version="1"]{position: relative; margin: 0 auto;}</style>' . PHP_EOL .
            '<script>window.onVMSAPIReady = function ( api ) {api.Article.enable();};</script>' . PHP_EOL .
            '<script src="http://go.verstka.io/api.js" type="text/javascript"></script>';
    }

    /**
     * @return string
     */
    public function getFailMessage()
    {
        return $this->failMessage;
    }

    /**
     * @return mixed
     */
    public function getHostname()
    {
        if ($this->hostname === null) {
            $info = parse_url($this->getCallbackUrl());
            $this->hostname = $info['host'];
        }
        return $this->hostname;
    }

    /**
     * @return int
     */
    public function getMaxRequestsPerBatch()
    {
        return $this->maxRequestsPerBatch;
    }

    /**
     * @return mixed
     */
    public function getSecret()
    {
        return $this->secret;
    }

    /**
     * @return string
     */
    public function getSuccessMessage()
    {
        return $this->successMessage;
    }

    /**
     * @return string
     */
    public function getTempDirectory()
    {
        if ($this->tempDirectory === null) {
            $this->setTempDirectory(sys_get_temp_dir());
        }
        return $this->tempDirectory;
    }

    /**
     * @return bool|string
     */
    public function getWebRoot()
    {
        if ($this->webRoot === null) {
            $this->setWebRoot($_SERVER['DOCUMENT_ROOT']);
        }
        return $this->webRoot;
    }

    /**
     * @param $request
     * @param callable $callback
     * @return bool|string
     */
    public function handleCallback($request, callable $callback)
    {
        if (empty($request['download_url']) || $this->makeSign($request) !== $request['callback_sign']) {
            return false;
        }

        set_time_limit(0);
        try {
            $resultJson = $this->requestUri($request['download_url'], ['api-key' => $this->apiKey]);
            $resultArray = $this->jsonDecode($resultJson);
            if ((int)$resultArray['rc'] !== self::ERROR_CODE_NONE) {
                throw new Exception($resultArray['rm']);
            }
            $result = $resultArray['data'];
            unset($resultJson, $resultArray);

            $tempDirectory = $this->getTempDirectory();
            $imagesToDownload = [];
            foreach ($result as $image) {
                if ($image === 'preview.png' || strpos($request['html_body'], $image) !== false) {
                    $imagesToDownload[] = [
                        'image' => $image,
                        'url' => $request['download_url'] . '/' . $image,
                        'download_to' => $tempDirectory . $image
                    ];
                }
            }

            $imagesToDownload = $this->multiRequest($imagesToDownload);
            $images = [];
            foreach ($imagesToDownload as $image) {
                if (!empty($imagesToDownload['result']) && $this->isForceLackingImages()) {
                    throw new Exception(var_export($imagesToDownload['result'], true)); // @todo: make error message
                }
                if (empty($imagesToDownload['result'])) {
                    $images[$image['image']] = $image['download_to'];
                }
            }
            unset($imagesToDownload, $result);

            $customFields = $this->jsonDecode($request['custom_fields']);
            $event = new CallbackEvent($request['html_body'], $request['material_id'], $request['user_id'], $images,
                $customFields);
            $callbackResult = call_user_func($callback, $event);

            if ($callbackResult === true) {
                foreach ($images as $image) {
                    if (is_readable($tempDirectory . $image)) {
                        unlink($tempDirectory . $image);
                    }
                }

                return $this->makeResponse(self::ERROR_CODE_NONE, $this->getSuccessMessage());
            }

            return $this->makeResponse(self::ERROR_CODE_FAIL, $this->getFailMessage(), (array)$callbackResult);
        } catch (\Exception $e) {
            $message = $this->isDebug() ? $e->getMessage() : $this->getFailMessage();
            return $this->makeResponse(self::ERROR_CODE_FAIL, $message, []);
        }
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @return bool
     */
    public function isForceLackingImages()
    {
        return $this->forceLackingImages;
    }

    public function makeTemplate($html)
    {
        $resultJson = $this->requestUri($this->apiUri . '/parse_images', [
            'POST' => [
                'article_body' => $html
            ]
        ]);
        $resultArray = $this->jsonDecode($resultJson);
        $result = $resultArray['data'];
        unset($resultJson, $resultArray);

        foreach ($result as $imageRelativePath) {
            $imagePath = $this->getWebRoot() . ltrim($imageRelativePath, '/');
            if (is_readable($imagePath) && is_file($imagePath)) {
                $type = pathinfo($imagePath, PATHINFO_EXTENSION);
                if ($type === 'svg') {
                    $type .= '+xml';
                }
                $data = file_get_contents($imagePath);
                $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
                $html = str_replace($imageRelativePath, $base64, $html);
            }
        }
        return $html;
    }

    /**
     * @param $content
     * @param $articleId
     * @param $userId
     * @param array $customFields
     * @return mixed
     * @throws Exception
     */
    public function open($content, $articleId, $userId, array $customFields = [])
    {
        $articleId = $this->normalizeArticleId($articleId);
        $userId = $this->normalizeUserId($userId);

        $params = [
            'POST' => [
                'user_id' => $userId,
                'user_ip' => $_SERVER['REMOTE_ADDR'],
                'material_id' => $articleId,
                'html_body' => $content,
                'callback_url' => $this->callbackUrl,
                'host_name' => $this->hostname,
                'api-key' => $this->apiKey,
                'custom_fields' => $this->jsonEncode($customFields)
            ]
        ];
        $params['POST']['callback_sign'] = $this->makeSign($params['POST']);
        $jsonResult = $this->requestUri($this->apiUri . '/open', $params);
        $result = $this->jsonDecode($jsonResult);

        if ((int)$result['rc'] !== self::ERROR_CODE_NONE) {
            throw new Exception($result['rm']);
        }

        if (!empty($result['data']['upload_url']) && !empty($result['data']['lacking_pictures'])) {
            $willUpload = [];
            $missingFiles = [];

            foreach ($result['data']['lacking_pictures'] as $lackingImage) {
                $lackingImagePath = $this->getWebRoot() . ltrim($lackingImage, '/');

                if (is_readable($lackingImagePath)) {
                    $willUpload[] = $lackingImagePath;
                } else {
                    $missingFiles[] = $lackingImagePath;
                }
            }

            if (!empty($willUpload)) {
                $uploadResultJson = $this->requestUri($result['data']['upload_url'], [
                    'api-key' => $this->apiKey,
                    'upload_from' => $willUpload
                ]);
                $uploadResult = $this->jsonDecode($uploadResultJson);
            }

            if ($this->forceLackingImages && !empty($missingFiles)) {
                throw new Exception('Missing ' . implode(PHP_EOL, $missingFiles) . ' in ' . $articleId);
            }

            if (empty($uploadResult)) {
                return $result['data'];
            }
            return $uploadResult['data'];
        }

        return $result['data'];
    }

    /**
     * @param mixed $apiKey
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @param string $apiUri
     */
    public function setApiUri($apiUri)
    {
        $this->apiUri = $apiUri;
    }

    /**
     * @param mixed $callbackUrl
     */
    public function setCallbackUrl($callbackUrl)
    {
        $this->callbackUrl = $callbackUrl;
    }

    /**
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param string $failMessage
     */
    public function setFailMessage($failMessage)
    {
        $this->failMessage = $failMessage;
    }

    /**
     * @param bool $forceLackingImages
     */
    public function setForceLackingImages($forceLackingImages)
    {
        $this->forceLackingImages = $forceLackingImages;
    }

    /**
     * @param mixed $hostname
     */
    public function setHostname($hostname)
    {
        $this->hostname = $hostname;
    }

    /**
     * @param int $maxRequestsPerBatch
     */
    public function setMaxRequestsPerBatch($maxRequestsPerBatch)
    {
        $this->maxRequestsPerBatch = $maxRequestsPerBatch;
    }

    /**
     * @param mixed $secret
     */
    public function setSecret($secret)
    {
        $this->secret = $secret;
    }

    /**
     * @param string $successMessage
     */
    public function setSuccessMessage($successMessage)
    {
        $this->successMessage = $successMessage;
    }

    /**
     * @param mixed|string $tempDirectory
     */
    public function setTempDirectory($tempDirectory)
    {
        $tempDirectory = $this->normalizePath($tempDirectory);
        if (!is_dir($this->tempDirectory)) {
            mkdir($tempDirectory, 0777, true);
        }
        $this->tempDirectory = $tempDirectory;
    }

    /**
     * @param bool|string $webRoot
     */
    public function setWebRoot($webRoot)
    {
        $this->webRoot = $this->normalizePath($webRoot);
    }
}
