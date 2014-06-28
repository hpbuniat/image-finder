<?php
/**
 * A small tool to check, if images are part of an istock-account
 *
 * - guzzle
 * - symfony crawler
 *
 * composer.install
 */

if (false === file_exists('vendor/autoload.php')) {
    print_r('please install dependencies with composer first: composer install' . PHP_EOL);
    exit;
}

require 'vendor/autoload.php';

class imageFinder {

    /**
     * The guzzle-client
     *
     * @var GuzzleHttp\Client
     */
    protected $_oGuzzle;

    /**
     * The istock-login
     *
     * @var string
     */
    protected $_sLogin;

    /**
     * The istock-password
     *
     * @var string
     */
    protected $_sPassword;

    /**
     * The cookie-container
     *
     * @var GuzzleHttp\Cookie\CookieJar()
     */
    protected $_oCookies;

    /**
     * The directory to scan
     *
     * @var string
     */
    protected $_sScanDirectory;

    /**
     * The directory for the istock-mirror
     *
     * @var string
     */
    protected $_sStockDirectory = './download';

    /**
     * Create the Finder
     *
     * @param  GuzzleHttp\Client $oClient
     * @param  array $aOpts
     */
    public function __construct(GuzzleHttp\Client $oClient, $aOpts) {

        $this->_oGuzzle        = $oClient;
        $this->_sLogin         = $aOpts['l'];
        $this->_sPassword      = $aOpts['p'];
        $this->_sScanDirectory = $aOpts['d'];
        if (false === is_dir($this->_sStockDirectory)) {
            mkdir($this->_sStockDirectory);
        }

        $this->_oCookies = new GuzzleHttp\Cookie\CookieJar();
    }

    /**
     * Trigger scan
     *
     * @return void
     */
    public function scan() {
        $oIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->_sScanDirectory));
        foreach($oIterator as $sFilename => $oPath) {
            if (is_file($sFilename) === true) {
                print_r(sprintf('comparing: %s', $sFilename) . PHP_EOL);
                $this->_compare($sFilename, $oPath);
            }
        }
    }

    /**
     * Compare an image with the downloaded mirror
     *
     * @param  string $sCompareFile
     * @param  SplFileInfo $oFile
     *
     * @return void
     */
    protected function _compare($sCompareFile, SplFileInfo $oFile) {

        $bFound = false;

        $oCompareImagick = new Imagick($sCompareFile);
        $oIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->_sStockDirectory));
        foreach ($oIterator as $sFilename => $oPath) {
            /* @var SplFileInfo $oPath */
            if (is_file($sFilename) === true) {
                try {
                    $oStockImagick = new Imagick($sFilename);
                    $result = $oCompareImagick->compareImages($oStockImagick, Imagick::METRIC_MEANSQUAREERROR);
                    if ((float) $result[1] === 0.0) {
                        $result = $oCompareImagick->compareImages($oStockImagick, Imagick::METRIC_ROOTMEANSQUAREDERROR);
                        if ((float) $result[1] === 0.0) {
                            print_r(sprintf('this is an istock-image: %s', $sFilename) . PHP_EOL);

                            $sOldFile = $oFile->getBasename('.' . $oFile->getExtension());
                            $sNewFile = sprintf('%s/%s-%s', $oFile->getPath(), $sOldFile, $oPath->getBasename());
                            rename($sCompareFile, $sNewFile);
                            print_r(sprintf('renamed image to: %s', $sNewFile) . PHP_EOL);

                            $bFound = true;
                            break;
                        }
                    }

                    unset($oStockImagick);
                }
                catch (ImagickException $e) {

                }
            }
        }

        if ($bFound === false) {
            print_r(sprintf('istock-image not found, try: https://www.tineye.com/') . PHP_EOL);
        }
    }

    /**
     * Login into istock account
     *
     * @return GuzzleHttp\Message\ResponseInterface
     */
    public function login() {

        $res = $this->_oGuzzle->get(
            'https://secure-deutsch.istockphoto.com/sign-in/',
            [
                'cookies' => $this->_oCookies
            ]
        );

        $sContent   = (string) $res;
        $oCrawler   = new Symfony\Component\DomCrawler\Crawler($sContent);
        $oDomNode   = $oCrawler->filter('#signInFormtoken')->getNode(0);
        $sFormToken = $oDomNode->getAttribute('value');

        $res = $this->_oGuzzle->post(
            'https://secure-deutsch.istockphoto.com/sign-in/',
            [
                'body'    => [
                    'credential'      => $this->_sPassword,
                    'identity'        => $this->_sLogin,
                    'submit'          => 'Anmelden',
                    'signInFormtoken' => $sFormToken
                ],
                'cookies' => $this->_oCookies
            ]
        );

        return $res;
    }

    /**
     * Execute mirroring of given istock-account
     *
     * @param  GuzzleHttp\Message\ResponseInterface $res
     *
     * @return void
     */
    public function save(GuzzleHttp\Message\ResponseInterface $res) {

        if ($res->getStatusCode() === '200') {
            $res = $this->_oGuzzle->get(
                'http://deutsch.istockphoto.com/my-account/download-history',
                [
                    'cookies' => $this->_oCookies
                ]
            );

            if ($res->getStatusCode() === '200') {
                $sContent       = (string) $res;
                $oCrawler       = new Symfony\Component\DomCrawler\Crawler($sContent);
                $oCrawlerResult = $oCrawler->filter('div.paginator span a');
                if ($oCrawlerResult->count() > 0) {
                    $oDomNode = $oCrawlerResult->getNode(0);
                    $iCount   = $oDomNode->getAttribute('title');
                    $iCounter = 1;
                    while ($iCounter <= $iCount) {
                        print_r(sprintf('parsing page %d of %d', $iCounter, $iCount) . PHP_EOL);
                        $res = $this->_oGuzzle->get(
                            sprintf('http://deutsch.istockphoto.com/my-account/download-history/index/page/%d', $iCounter),
                            [
                                'cookies' => $this->_oCookies
                            ]
                        );
                        if ($res->getStatusCode() === '200') {
                            $this->_parsePage($res);
                        }

                        $iCounter++;
                    }
                }
            }
        }
    }

    /**
     * Parse a page
     *
     * @param  GuzzleHttp\Message\ResponseInterface $res
     *
     * @return void
     */
    protected function _parsePage(GuzzleHttp\Message\ResponseInterface $res) {

        $sContent       = (string) $res;
        $oCrawler       = new Symfony\Component\DomCrawler\Crawler($sContent);
        $oCrawlerResult = $oCrawler->filter('#mncntnt table tr');

        $aDownload = array();
        foreach ($oCrawlerResult as $oResult) {
            $oDownloadCrawler       = new Symfony\Component\DomCrawler\Crawler($oResult);
            $oDownloadCrawlerResult = $oDownloadCrawler->filter('td a');
            if ($oDownloadCrawlerResult->count() > 0) {
                $oDomNode  = $oDownloadCrawlerResult->getNode(3);
                $sDownload = $oDomNode->getAttribute('href');

                $oImgNode    = $oDownloadCrawler->filter('td a img')->getNode(0);
                $sImage      = $oImgNode->getAttribute('src');
                $aExplode    = explode('/', $sImage);
                $sFileName   = end($aExplode);
                $aDownload[] = [
                    'file' => $sFileName,
                    'url'  => $sDownload
                ];
            }
        }

        if (false === empty($aDownload)) {
            $this->_download($aDownload);
        }
    }

    /**
     * Execute downloader
     *
     * @param  array $aDownloads
     *
     * @return void
     */
    protected function _download($aDownloads) {

        $requests = [];
        foreach ($aDownloads as $aDownload) {
            $sFinalPath = sprintf('%s/%s', $this->_sStockDirectory, $aDownload['file']);
            if (false === file_exists($sFinalPath)) {
                print_r(sprintf('download: %s to %s', $aDownload['url'], $sFinalPath) . PHP_EOL);
                $requests[] = $this->_oGuzzle->createRequest(
                    'GET',
                    $aDownload['url'],
                    [
                        'cookies' => $this->_oCookies,
                        'save_to' => $sFinalPath
                    ]
                );
            }
        }

        if (false === empty($requests)) {
            print_r(sprintf('loading %d file', count($requests)) . PHP_EOL);
            $this->_oGuzzle->sendAll($requests);
        }
    }
}

// execution
$aOpts = getopt('l:p:s:d:');
if (true === empty($aOpts['l']) or true === empty($aOpts['p']) or true === empty($aOpts['d'])) {
    print_r(sprintf('usage: %s -l login -p password -d compare-dir [-s true]', $argv[0]) . PHP_EOL);
    exit;
}

$client = new GuzzleHttp\Client();
$o      = new imageFinder($client, $aOpts);
if (true === empty($aOpts['s'])) {
    $res = $o->login();
    $o->save($res);
}

$o->scan();
