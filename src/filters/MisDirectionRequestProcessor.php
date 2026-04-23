<?php

namespace nglasl\misdirection;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ErrorPage\ErrorPage;

/**
 * Middleware used to process requests and redirect if a match is found
 */
class MisDirectionRequestProcessor implements HTTPMiddleware
{
    use Configurable;
    use Injectable;

    private static array $status_codes = [
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect'
    ];

    public $service;

    private static array $dependencies = [
        'service' => '%$' . MisdirectionService::class
    ];

    private static bool $enforce_misdirection = true;

    private static bool $replace_default = false;

    /**
     *	The maximum number of consecutive link mappings.
     */
    private static int $maximum_requests = 9;

    public function process(HTTPRequest $request, callable $delegate)
    {
        /** @var \SilverStripe\Control\HTTPResponse $response */
        $response = $delegate($request);
        $requestURL = $request->getURL();
        $bypass = [
            'admin',
            'Security',
            'CMSSecurity',
            'dev'
        ];

        foreach (Director::config()->get('rules') as $segment => $controller) {

            // Retrieve the specific director rules.
            if (($position = strpos($segment ?? '', '$')) !== false) {
                $segment = rtrim(substr($segment ?? '', 0, $position), '/');
            }

            // Determine if the current request matches a specific director rule.
            if ($segment && in_array($segment, $bypass) && (($requestURL === $segment) || (str_starts_with($requestURL, "{$segment}/")))) {

                // Continue processing the response.
                return $response;
            }

            if ($request->getVar('misdirected') || $request->getVar('direct')) {
                // Continue processing the response.
                return $response;
            }
        }

        if ($response) {

            $status = $response->getStatusCode();
            $success = (($status >= 200) && ($status < 300));
            $error = ($status === 404);

            $enforce = $this->config()->get('enforce_misdirection');
            $replace = $this->config()->get('replace_default');

            if (($error || $enforce || $replace) && ($map = $this->service->getMappingByRequest($request))) {

                $responseCode = $map->ResponseCode;
                if ($responseCode == 0) {
                    $responseCode = 301;
                }

                $link = $map->getLink();
                $base = Director::baseURL();
                if ($replace && (str_starts_with((string) $link, $base)) && (substr((string) $link, strlen($base)) === MisdirectionService::getHomeSegment())) {
                    $link = $base;
                }

                // Update the response using the link mapping redirection.
                $response->setBody('');
                $response->redirect($link, $responseCode);
            } elseif ($error && ($fallback = $this->service->determineFallback($requestURL))) {
                // Update the response code where appropriate.
                $responseCode = $fallback['code'];
                if ($responseCode === 0) {
                    $responseCode = 303;
                }

                // Update the response using the fallback, enforcing no further redirection.
                $response->setBody('');
                $response->redirect($fallback['link'], $responseCode);
            } elseif (!$error && !$success && $replace) {
                // When enabled, replace the default automated URL handling with a page not found.
                $response->setStatusCode(404);
                // Retrieve the appropriate page not found response.
                (class_exists(ErrorPage::class) && ($page = ErrorPage::response_for(404))) ? $response->setBody($page->getBody()) : $response->setBody('No URL was matched!');
            }

        }

        return $response;

    }

}
