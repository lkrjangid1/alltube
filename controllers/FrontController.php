<?php
/**
 * FrontController class
 *
 * PHP Version 5.3.10
 *
 * @category Youtube-dl
 * @package  Youtubedl
 * @author   Pierre Rudloff <contact@rudloff.pro>
 * @license  GNU General Public License http://www.gnu.org/licenses/gpl.html
 * @link     http://rudloff.pro
 * */
namespace Alltube\Controller;

use Alltube\VideoDownload;
use Alltube\Config;
use Symfony\Component\Process\ProcessBuilder;
use Chain\Chain;
use ProcessStream\PopenStream;

/**
 * Main controller
 *
 * PHP Version 5.3.10
 *
 * @category Youtube-dl
 * @package  Youtubedl
 * @author   Pierre Rudloff <contact@rudloff.pro>
 * @license  GNU General Public License http://www.gnu.org/licenses/gpl.html
 * @link     http://rudloff.pro
 * */
class FrontController
{
    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->download = new VideoDownload();
    }

    /**
     * Display index page
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     *
     * @return void
     */
    public function index($request, $response)
    {
        global $container;
        $container->view->render(
            $response,
            'head.tpl',
            array(
                'class'=>'index'
            )
        );
        $container->view->render(
            $response,
            'header.tpl'
        );
        $container->view->render(
            $response,
            'index.tpl',
            array(
                'convert'=>$this->config->convert
            )
        );
        $container->view->render($response, 'footer.tpl');
    }

    /**
     * Display a list of extractors
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     *
     * @return void
     */
    public function extractors($request, $response)
    {
        global $container;
        $container->view->render(
            $response,
            'head.tpl',
            array(
                'class'=>'extractors'
            )
        );
        $container->view->render($response, 'header.tpl');
        $container->view->render($response, 'logo.tpl');
        $container->view->render(
            $response,
            'extractors.tpl',
            array(
                'extractors'=>$this->download->listExtractors()
            )
        );
        $container->view->render($response, 'footer.tpl');
    }

    /**
     * Dislay information about the video
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     *
     * @return void
     */
    public function video($request, $response)
    {
        global $container;
        $params = $request->getQueryParams();
        $this->config = Config::getInstance();
        if (isset($params["url"])) {
            if (isset($params['audio'])) {
                try {
                    try {
                        return $this->getStream($params["url"], 'bestaudio[protocol^=http]', $response, $request);
                    } catch (\Exception $e) {
                        $video = $this->download->getJSON($params["url"], 'best');

                        $avconvProc = ProcessBuilder::create(
                            array(
                                $this->config->avconv,
                                '-v', 'quiet',
                                '-i', '-',
                                '-f', 'mp3',
                                '-vn',
                                'pipe:1'
                            )
                        );

                        //Vimeo needs a correct user-agent
                        ini_set(
                            'user_agent',
                            $video->http_headers->{'User-Agent'}
                        );

                        $response = $response->withHeader(
                            'Content-Disposition',
                            'attachment; filename="'.
                            html_entity_decode(
                                pathinfo(
                                    $video->_filename,
                                    PATHINFO_FILENAME
                                ).'.mp3',
                                ENT_COMPAT,
                                'ISO-8859-1'
                            ).'"'
                        );
                        $response = $response->withHeader('Content-Type', 'audio/mpeg');

                        if (parse_url($video->url, PHP_URL_SCHEME) == 'rtmp') {
                            $builder = new ProcessBuilder(
                                array(
                                    $this->config->rtmpdump,
                                    '-q',
                                    '-r',
                                    $video->url,
                                    '--pageUrl', $video->webpage_url
                                )
                            );
                            if (isset($video->player_url)) {
                                $builder->add('--swfVfy');
                                $builder->add($video->player_url);
                            }
                            if (isset($video->flash_version)) {
                                $builder->add('--flashVer');
                                $builder->add($video->flash_version);
                            }
                            if (isset($video->play_path)) {
                                $builder->add('--playpath');
                                $builder->add($video->play_path);
                            }
                            foreach ($video->rtmp_conn as $conn) {
                                $builder->add('--conn');
                                $builder->add($conn);
                            }
                            $chain = new Chain($builder->getProcess());
                            $chain->add('|', $avconvProc);
                        } else {
                            $chain = new Chain(
                                ProcessBuilder::create(
                                    array_merge(
                                        array(
                                            'curl',
                                            '--silent',
                                            '--user-agent', $video->http_headers->{'User-Agent'},
                                            $video->url
                                        ),
                                        $this->config->curl_params
                                    )
                                )
                            );
                            $chain->add('|', $avconvProc);
                        }
                        return $response->withBody(new PopenStream($chain->getProcess()->getCommandLine()));
                    }
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                }
            } else {
                try {
                    $video = $this->download->getJSON($params["url"]);
                    $container->view->render(
                        $response,
                        'head.tpl',
                        array(
                            'class'=>'video'
                        )
                    );
                    $container->view->render(
                        $response,
                        'video.tpl',
                        array(
                            'video'=>$video
                        )
                    );
                    $container->view->render($response, 'footer.tpl');
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
        if (isset($error)) {
            $container->view->render(
                $response,
                'head.tpl',
                array(
                    'class'=>'video'
                )
            );
            $container->view->render(
                $response,
                'error.tpl',
                array(
                    'errors'=>$error
                )
            );
            $container->view->render($response, 'footer.tpl');
        }
    }

    private function getStream($url, $format, $response, $request)
    {
        if (!isset($format)) {
            $format = 'best';
        }
        $video = $this->download->getJSON($url, $format);
        $client = new \GuzzleHttp\Client();
        $stream = $client->request('GET', $video->url, array('stream'=>true));
        $response = $response->withHeader('Content-Disposition', 'attachment; filename="'.$video->_filename.'"');
        $response = $response->withHeader('Content-Type', $stream->getHeader('Content-Type'));
        $response = $response->withHeader('Content-Length', $stream->getHeader('Content-Length'));
        if ($request->isGet()) {
            $response = $response->withBody($stream->getBody());
        }
        return $response;
    }

    /**
     * Redirect to video file
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     *
     * @return void
     */
    public function redirect($request, $response)
    {
        global $app;
        $params = $request->getQueryParams();
        if (isset($params["url"])) {
            try {
                return $this->getStream($params["url"], $params["format"], $response, $request);
            } catch (\Exception $e) {
                $response->getBody()->write($e->getMessage());
                return $response->withHeader('Content-Type', 'text/plain');
            }
        }
    }

    /**
     * Output JSON info about the video
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     *
     * @return void
     */
    public function json($request, $response)
    {
        global $app;
        $params = $request->getQueryParams();
        if (isset($params["url"])) {
            try {
                $video = $this->download->getJSON($params["url"]);
                return $response->withJson($video);
            } catch (\Exception $e) {
                return $response->withJson(
                    array('success'=>false, 'error'=>$e->getMessage())
                );
            }
        }
    }
}
