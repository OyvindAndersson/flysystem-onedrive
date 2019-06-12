<?php

namespace JacekBarecki\FlysystemOneDrive\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Stream;

class OneDriveClient
{
    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var Client
     */
    private $guzzle;

    private $config;

    protected $drive_id = '';

    const BASE_URI = 'https://graph.microsoft.com/v1.0/';

    /**
     * @param string $accessToken
     * @param Client $guzzle
     */
    public function __construct($accessToken, Client $guzzle, $drive_id = '' )
    {
        $this->accessToken = $accessToken;
        $this->guzzle = $guzzle;

        $this->drive_id = $drive_id;
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/download.htm
     */
    public function download($path)
    {
        $url = $this->getDownloadUrlForFile($path);

        return $this->getResponse('GET', $url);
    }

    /**
     * @param string $path
     *
     * @return resource
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/download.htm
     */
    public function downloadStream($path)
    {
        $url = $this->getDownloadUrlForFile($path);
        $input = $this->getStreamForUrl($url);

        $output = fopen('php://temp', 'w+');
        if ($output === false) {
            throw new OneDriveClientException('Error when saving the downloaded file');
        }

        while (!$input->eof()) {
            $writeResult = fwrite($output, $input->read(8192));
            if ($writeResult === false) {
                throw new OneDriveClientException('Error when saving the downloaded file');
            }
        }

        $input->close();

        rewind($output);

        return $output;
    }

    /**
     * @param string $url
     *
     * @return Stream
     */
    public function getStreamForUrl($url)
    {
        $resource = fopen($url, 'r');

        return new Stream($resource);
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/get.htm
     */
    public function getMetadata($path)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);
 
        return $this->getResponse('GET', $url);

        /*
        $response = [];
        $found = false;

        while(!$found)
        {
            try
            {
                $response = $this->getResponse('GET', $url);

                return $response;
            }
            catch(\GuzzleHttp\Exception\ClientException $e)
            {
                if( $e->getResponse()->getStatusCode() == '404')
                {
                    //wait some time until the next status check
                    sleep(0.5);
                }
                else
                {
                    $found = true;
                    dd($e);
                }
            }
        }*/
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    public function createFile($path, $content)
    {
        return $this->simpleItemUpload($path, $content, 'fail');
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    public function updateFile($path, $content)
    {
        return $this->simpleItemUpload($path, $content, 'replace');
    }

    /**
     * @param string $oldPath
     * @param string $newPath
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function rename($oldPath, $newPath)
    {
        $payload = $this->getNewFileLocationPayload($newPath);

        return $this->updateMetadata($oldPath, $payload);
    }

    /**
     * @param string $oldPath
     * @param string $newPath
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://docs.microsoft.com/en-us/graph/long-running-actions-overview?tabs=cs
     * @link https://dev.onedrive.com/items/copy.htm
     */
    public function copy($oldPath, $newPath)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($oldPath).':/copy';
        
        $payload = $this->getNewFileLocationPayload($newPath, $oldPath);

        $headers = [
            'Content-Type' => 'application/json',
            'Prefer' => 'respond-async',
        ];
        
        try
        {
            $response = $this->getResponse('POST', $url, json_encode($payload), $headers);
            $asyncStatusLocation = $response->getHeader('Location');

            $completed = false;
            while (!$completed) {
                // The route for checking progress is public - no need for auth
                $statusResponse = $this->getResponseUnsigned('GET', $asyncStatusLocation[0]);
                $status = $statusResponse->getBody()->getContents();
                $status = json_decode($status);
                

                if ($status->status == 'completed') 
                {
                    $completed = true;
                } 
                else 
                {
                    //echo $status->percentageComplete;

                    if ($status->status == 'failed') {
                        throw new OneDriveClientException('API error when copying the file');
                    }

                    //wait some time until the next status check
                    sleep(0.2);
                }
            }

            return $this->getMetadata($newPath);
            
        }
        catch(\GuzzleHttp\Exception\ClientException $e)
        {
            dd($e->getResponse()->getBody()->getContents(), $e);
        }
        catch(\GuzzleHttp\Exception\RequestException $e)
        {
            dd($e->getResponse()->getBody()->getContents(), $e);
        }

        
    }

    /**
     * @param string $path
     * @param array  $metadata
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/update.htm
     */
    public function updateMetadata($path, array $metadata)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);

        return $this->getResponse('PATCH', $url, json_encode($metadata), ['Content-Type' => 'application/json']);
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/create.htm
     */
    public function createFolder($path)
    {
        $pathinfo = pathinfo($path);

        $parentDir = ($pathinfo['dirname'] != '.') ? $pathinfo['dirname'] : '';
        $url = self::BASE_URI.$this->getFolderUrl($path, true);//.'children';
        
        $folder = new Folder();
        $folder->conflictBehavior = 'rename';

        return $this->getResponse('PATCH', $url, json_encode( $folder ), ['Content-Type' => 'application/json']);
        

        /*
        $pathinfo = pathinfo($path);

        $parentDir = ($pathinfo['dirname'] != '.') ? $pathinfo['dirname'] : '';
        $url = self::BASE_URI.$this->getFolderUrl($parentDir).'children';
        
        $folder = new Folder();
        $folder->name = $pathinfo['basename'];
        $folder->conflictBehavior = 'rename';

        return $this->getResponse('POST', $url, json_encode( $folder ), ['Content-Type' => 'application/json']);
        */
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/list.htm
     */
    public function listChildren($path)
    {
        $url = self::BASE_URI.$this->getFolderUrl($path).'children';
        return $this->getResponse('GET', $url);
    }

    /**
     * @param string $path
     *
     * @return bool Success
     *
     * @link https://dev.onedrive.com/items/delete.htm
     */
    public function delete($path)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);

        $response = $this->getResponse('DELETE', $url);
        $success = ($response->getStatusCode() == '204');

        return $success;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function itemExists($path)
    {
        try {
            $this->getMetadata($path);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == '404') {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $conflictBehavior fail|replace|rename
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    private function simpleItemUpload($path, $content, $conflictBehavior)
    {
        if (!in_array($conflictBehavior, ['fail', 'replace', 'rename'])) {
            throw new OneDriveClientException('Incorrect conflict behavior parameter value');
        }

        $url = self::BASE_URI.$this->getPathUnderRootDrive($path).':/content?@name.conflictBehavior='.$conflictBehavior;

        return $this->getResponse('PUT', $url, $content);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function getDownloadUrlForFile($path)
    {
        $metadataRaw = $this->getMetadata($path)->getBody()->getContents();
        $metadata = json_decode($metadataRaw);

        return $metadata->{'@content.downloadUrl'};
    }

    /**
     * Returns the payload for a copy/rename request.
     *
     * @param string $newPath
     *
     * @return array
     */
    private function getNewFileLocationPayload($newPath, $oldPath)
    {
        $pathinfo = pathinfo($newPath);
        $newParentDir = ($pathinfo['dirname'] != '.') ? $pathinfo['dirname'] : '';
        $newFilename = $pathinfo['basename'];

        $payload = [
            'name' => $newFilename,
            'parentReference' => ['path' => $newParentDir],
        ];

        return $payload;
    }

    /**
     * @param string $folder
     *
     * @return string
     */
    private function getParentReferenceFolder($folder)
    {
        if ($folder == '') {
            $parentReference = '/drives/'.$this->drive_id.'/root/';
        } else {
            $parentReference = '/drives/'.$this->drive_id.'/root:/'.$folder;
        }

        return $parentReference;
    }

    /**
     * @param string $folder
     *
     * @return string
     */
    private function getFolderUrl($folder, $ignoreResourceUri = false)
    {
        if ($folder == '') 
        {
            $url = 'drives/'.$this->drive_id.'/root/';
        } 
        else if($ignoreResourceUri)
        {
            $url = $this->getPathUnderRootDrive($folder);
        }
        else 
        {
            $url = $this->getPathUnderRootDrive($folder).':/';
        }
        /*
        if ($folder == '') {
            $url = 'drives/'.$this->drive_id.'/root/children';
        } else {
            $url = $this->getPathUnderRootDrive($folder).':/children';
        }*/

        return $url;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function getPathUnderRootDrive($path)
    {
        return 'drives/'.$this->drive_id.'/root:/'.$path;
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array  $headers
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function getResponse($method, $path, $body = null, $headers = [])
    {
        $allHeaders = array_merge($this->getAuthorizationHeader(), $headers);
        $uri = $path;

        $request = new Request($method, $uri, $allHeaders, $body);

        return $this->guzzle->send($request);
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array  $headers
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function getResponseUnsigned($method, $path, $body = null, $headers = [])
    {
        $uri = $path;

        $request = new Request($method, $uri, $headers, $body);

        return $this->guzzle->send($request);
    }

    /**
     * @return array
     *
     * @link https://dev.onedrive.com/auth/msa_oauth.htm
     */
    private function getAuthorizationHeader()
    {
        return ['Authorization' => 'bearer '.$this->accessToken];
    }
}