<?php

/**
 * You can use this adapter and upload as guest but use account
 * is recommend.
 *
 * To use account, require the options: username, password
 */
namespace RemoteImageUploader\Adapters;

use RemoteImageUploader\Factory;
use RemoteImageUploader\Interfaces\Account;
use RemoteImageUploader\Helper;
use Exception;

class Postimage extends Factory implements Account
{
    const UPLOAD_MAX_FILE_SIZE = 16777216; // 16MB - limited by postimage.
    const GUEST_UPLOAD_ENDPOINT = 'http://old.postimage.org/';
    const USER_UPLOAD_ENDPOINT = 'http://old.postimg.org/';
    const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:38.0) Gecko/20100101 Firefox/38.0';

    private $useAccount = false;

    /**
     * {@inheritdoc}
     */
    public function getOptions()
    {
        return array_merge(parent::getOptions(), array(
            'username'   => null,
            'password'   => null,

            // if you want to upload photos to specific gallery id (album id)
            // you can use this option. You will found it in My Images menu
            // when you logged in.
            // http://postimg.org/my.php?gallery=GALLERY_ID
            'gallery' => null,
        ));
    }

    /**
     * @inheritdoc
     */
    public function login()
    {
        $this->useAccount = true;

        if (!$this->isLoggedIn()) {
            $request = $this->createRequest('http://postimage.org/auth.php', 'POST')
                ->withFormParam(array(
                    'login'    => $this['username'],
                    'password' => $this['password'],
                ))
                ->withFollowRedirects(2)
                ->send();

            $cookies = $request->getAllResponseCookies();
            foreach ($cookies as $c) {
                if ($c['Name'] == 'userlogin') {
                    $lifeTime = max(900, strtotime($c['Expires']) - time() - 86400 / 2);

                    $this->setData('login', $cookies, $lifeTime);
                    $success = true;
                    break;
                }
            }
            if (empty($success)) {
                throw new Exception('Login failed');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getLoginData()
    {
        return $this->useAccount ? $this->getData('login', array()) : array();
    }

    /**
     * {@inheritdoc}
     */
    public function isLoggedIn()
    {
        return $this->useAccount && $this->hasData('login');
    }

    /**
     * @inheritdoc
     */
    protected function doUpload($file)
    {
        $endpoint = $this->getUrlEnpoint();
        $cookies = $this->getLoginData();

        $params = array(
            'um'             => 'computer',
            'gallery_id'     => '',
            'upload_error'   => '',
            'session_upload' => time() * 1000 + mt_rand(0, 1000),
            'MAX_FILE_SIZE'  => self::UPLOAD_MAX_FILE_SIZE,
        );

        if ($gallery = $this->getGalleryId()) {
            $params['gallery'] = $gallery;
        }

        $request = $this->createRequest($endpoint, 'POST')
            ->withCookie($cookies)
            ->withHeader('Referer', $endpoint)
            ->withFormParam($this->getGeneralParams())
            ->withFormParam($params)
            ->withFormFile('upload', $file)
            ->send();

        if (!preg_match('#^\w+$#', $request)) {
            throw new Exception('Upload failed');
        }

        $params = array(
            'upload[]'   => '',
            'gallery_id' => (string) $request,
        ) + $params;

        $request = $this->createRequest($endpoint, 'POST')
            ->withCookie($cookies)
            ->withFormParam($this->getGeneralParams())
            ->withFormParam($params)
            ->withFollowRedirects(2)
            ->send();
            $uri = $request->request['uri'];
            $request = file_get_contents($uri);
        return $this->getImageUrl($request);
    }

    /**
     * @inheritdoc
     */
    protected function doTransload($url)
    {
        $endpoint = $this->getUrlEnpoint();
        $cookies = $this->getLoginData();

        $params = array(
            'um'       => 'web',
            'url_list' => $url,
        );

        if ($gallery = $this->getGalleryId()) {
            $params['gallery'] = $gallery;
        }

        $request = $this->createRequest($endpoint, 'POST')
            ->withCookie($cookies)
            ->withFormParam($this->getGeneralParams())
            ->withFormParam($params)
            ->withFollowRedirects(1)
            ->send();
            $uri = $request->request['uri'];
            $request = file_get_contents($uri);
        return $this->getImageUrl($request);
    }

    /**
     * @inheritdoc
     */
    protected function createRequest($url, $method = 'GET')
    {
        return parent::createRequest($url, $method)
            ->withHeader('User-Agent', self::DEFAULT_USER_AGENT);
    }

    private function getImageUrl($request)
    {
        if (!stripos($request, 'Direct Link')
            || !$url = Helper::match('#id="code_2"[^>]*?>(http[^<]+)#', $request)
        ) {
            throw new Exception(sprintf('Not found direct link.', __METHOD__));
        }

        return $url;
    }

    private function getGalleryId()
    {
        if (isset($this['gallery'])) {
            return $this['gallery'];
        }
        if ($this->isLoggedIn()) {
            if (!$galleryList = $this->getData('gallerylist')) {
                $request = $this->createRequest('http://postimg.org/my.php')
                    ->withCookie($this->getLoginData())
                    ->send();

                if (preg_match_all('#\.php\?gallery=(\w+)\'#i', $request, $matches)) {
                    $galleryList = array_unique($matches[1]);
                    $this->setData('gallerylist', $galleryList, 900);
                }
            }
            if ($galleryList) {
                return $galleryList[array_rand($galleryList)];
            }
        }

        return false;
    }

    private function getUrlEnpoint()
    {
        return $this->useAccount ? self::USER_UPLOAD_ENDPOINT : self::GUEST_UPLOAD_ENDPOINT;
    }

    private function getGeneralParams()
    {
        $endpoint = $this->getUrlEnpoint();
        $ui = sprintf('24__1440__900__true__?__?__%s__%s__', date('m/d/Y, h:i:s A'), self::DEFAULT_USER_AGENT);

        return array(
            'mode'           => 'local',
            'areaid'         => '',
            'hash'           => '',
            'code'           => '',
            'content'        => '',
            'tpl'            => '.',
            'ver'            => '',
            'addform'        => '',
            'mforum'         => '',
            'forumurl'       => $endpoint,
            'adult'          => 'no',
            'optsize'        => 0,
            'ui'             => $ui
        );
    }
}
