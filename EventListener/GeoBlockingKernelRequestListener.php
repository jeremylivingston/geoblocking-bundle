<?php
namespace Azine\GeoBlockingBundle\EventListener;

use Psr\Log\LoggerInterface;

use FOS\UserBundle\Model\UserInterface;

use Symfony\Component\HttpKernel\HttpKernelInterface;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;

use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

use Azine\GeoBlockingBundle\Adapter\GeoIpLookupAdapterInterface;

class GeoBlockingKernelRequestListener{
	private $configParams;
	private $lookUpAdapter;
	private $templating;
	private $logger;


	public function __construct(EngineInterface $templating, GeoIpLookupAdapterInterface $lookupAdapter, LoggerInterface $logger,  array $parameters){
		$this->configParams = $parameters;
		$this->lookUpAdapter 	= $lookupAdapter;
		$this->templating = $templating;
		$this->logger = $logger;
	}

	public function onKernelRequest(GetResponseEvent $event)
	{
		// ignore sub-requests
		if($event->getRequestType() == HttpKernelInterface::SUB_REQUEST){
			return;
		}

		// check if the blocking is enabled at all
		if(!$this->configParams['enabled']){
			$this->logger->info("azine_geoblocking_bundle: blocking not enabled");
			return;
		}

		$request = $event->getRequest();
		// check if blocking authenticated users is enabled
		$authenticated = $request->getUser() instanceof UserInterface;
		if($this->configParams['blockAnonOnly'] && $authenticated){
			$this->logger->info("azine_geoblocking_bundle: allowed logged-in user");
			return;
		}

		$visitorAddress = $request->getClientIp();

		// check if the visitors IP is a private IP => the request comes from the same subnet as the server or the server it self.
		if($this->configParams['allowPrivateIPs']){
			$patternForPrivateIPs = "#(^127\.0\.0\.1)|(^10\.)|(^172\.1[6-9]\.)|(^172\.2[0-9]\.)|(^172\.3[0-1]\.)|(^192\.168\.)#";
			if(preg_match($patternForPrivateIPs, $visitorAddress) == 1){
				$this->logger->info("azine_geoblocking_bundle: allowed private network");
				return;
			}
		}

	   	// check if the route is allowed from the current visitors country via whitelists
		$routeName = $request->get('_route');
		$allowedByRouteWhiteList = array_search($routeName, $this->configParams['routeWhitelist'], true) === false;
		if(!$allowedByRouteWhiteList){
			$this->logger->info("azine_geoblocking_bundle: allowed by routeWhiteList");
			return;
		}

		$country = $this->lookUpAdapter->getCountry($visitorAddress);
		$allowedByCountryWhiteList = array_search($country, $this->configParams['countryWhitelist'], true) === false;
		if(!$allowedByCountryWhiteList){
			$this->logger->info("azine_geoblocking_bundle: allowed by countryWhiteList");
			return;
		}

		// check if the vistitor is a whitelisted IP
		if($this->isAllowedByIpWhiteListConfig($visitorAddress)){
			$this->logger->info("azine_geoblocking_bundle: allowed by ipWhiteList");
			return;
		}

		// check if the visitor is allowed because it's a search-engine crawler of google or msn
		if($this->isAllowedBecauseIpIsSearchEngingeCrawler($visitorAddress)){
			$this->logger->info("azine_geoblocking_bundle: allowed by searchEngineConfig");
			return true;
		}


		$useRouteBL = !empty($this->configParams['routeBlacklist']);
		$useCountryBL = !empty($this->configParams['countryBlacklist']);

		if(!$useRouteBL && !$useCountryBL){
			$this->logger->warning("azine_geoblocking_bundle: blocked by routeBL or countryBL (!useRouteBL($useRouteBL) && !useCountryBL($useCountryBL))");
			$this->blockAccess($event, $country);
			return;
		}

		// check if one of the blacklists denies access
		if($useRouteBL){
			if(array_search($routeName, $this->configParams['routeBlacklist'], true) !== false){
				$this->logger->warning("azine_geoblocking_bundle: blocked by routeBL.\n".print_r($this->configParams['routeBlacklist'], true));
				$this->blockAccess($event, $country);
			}
		}

		if($useCountryBL){
			if(array_search($country, $this->configParams['countryBlacklist'], true) !== false){
				$this->logger->warning("azine_geoblocking_bundle: blocked by countryBL\n".print_r($this->configParams['countryBlacklist'], true));
				$this->blockAccess($event, $country);
			}
		}

		$this->logger->info("azine_geoblocking_bundle: allowed, no denial-rule triggered");
		return;
	}

	private function blockAccess(GetResponseEvent $event, $country){
		// render the "sorry you are not allowed here"-page
		$parameters = array('loginRoute' => $this->configParams['loginRoute'], 'country' => $country);
		$event->setResponse($this->templating->renderResponse($this->configParams['blockedPageView'], $parameters));
		$event->stopPropagation();

		if($this->configParams['logBlockedRequests']){
			$request = $event->getRequest();
			$routeName = $request->get('_route');
			$ip = $request->getClientIp();
			$uagent = $_SERVER['HTTP_USER_AGENT'];
			$hostName = gethostbyaddr($ip);
			$this->logger->warning("azine_geoblocking_bundle: Route $routeName was blocked for a user from $country (IP: $ip , HostName $hostName, UAgent: '$uagent'");
		}
	}

	private function isAllowedByIpWhiteListConfig($ip){
		if($this->configParams['ip_whitelist']){
			foreach ($this->configParams['ip_whitelist'] as $pattern){
				if($ip == $pattern || @preg_match($pattern, $ip) === 1){
					return true;
				}
			}
		}
		return false;
	}

	private function isAllowedBecauseIpIsSearchEngingeCrawler($ip){
		if($this->configParams['allow_search_bots']){

			// resolve host name
			$hostName = gethostbyaddr($ip);
			// reverse resolve IP
			$reverseIP = gethostbyname($hostName);


			// chekc if the hostname matches any of the search-engine names.
			$searchEngineDomains = $this->configParams['search_bot_domains'];

			$isSearchEngineDomain = false;
			$uagent = $_SERVER['HTTP_USER_AGENT'];
			foreach ($searchEngineDomains as $domain){

				// if the hostname ends with any of the search-engine-domain names
				if(substr($hostName, - strlen($domain)) === $domain){

					// set variable to true and stop the loop
					$isSearchEngineDomain = true;
					break;
				}
			}


			// if the IP and reverse resolved IP match and the ip belongs to a search-engine-domain
			if($ip == $reverseIP && $isSearchEngineDomain){
				// allow the ip
				return true;
			}
		}
		return false;
	}
}