<?php


namespace  CodingFreaks\CfCookiemanager\Utility;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use CodingFreaks\CfCookiemanager\Event\ClassifyContentEvent;
use Masterminds\HTML5;

/**
 * Class RenderUtility
 * @package CodingFreaks\CfCookiemanager\Utility
 *
 * TODO: Refactor this class to use a PSR-14 EventDispatcher
 * TODO: Currently overrideIframes and overrideScript are copied to new functions replaceIframes and replaceScript to test the new Regex Method, refactor this to use the same function with string replace or dom override.
 */
class RenderUtility
{

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * check if string contains valid html
     *
     * @param string $html
     * @return boolean
     */
    function isHTML($html){
        // remove all scripts and iframes if found in HTML content
        if($html != strip_tags($html) && (strpos($html, '<iframe') !== false || strpos($html, '<script') !== false)){
            return true;  // if string is HTML
        }else{
            return false; // if string is not HTML
        }
    }

    /**
     * Find and replace a script tag and override the attribute to text/plain
     *
     * @param string $html
     * @param string $databaseRow
     * @param array $extensionConfiguration
     * @return string
     */
    public function overrideScript($html, $databaseRow, $extensionConfiguration): string
    {
        if(!$this->isHTML($html)){
            return $html;
        }

        $html5 = new HTML5(['disable_html_ns' => true]);
        $dom = $html5->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $scripts = $xpath->query('//script');
        foreach ($scripts as $script) {
            $attributes = array();
            foreach ($script->attributes as $attr) {
                // Validate and sanitize attribute values
                $attrValue = htmlentities($attr->value, ENT_QUOTES, 'UTF-8');
                $attributes[$attr->name] = $attrValue;
            }
            if(empty($attributes["src"])){
                //Skip inline Scripts
                continue;
            }

            $serviceIdentifier = $this->classifyContent($attributes["src"]);
            if(empty($serviceIdentifier)){
                if(intval($extensionConfiguration["scriptBlocking"]) === 1){
                    //Script Blocking is enabled so Block all Scripts and Iframes
                    if(empty($attributes["data-script-blocking-disabled"]) || (!empty($attributes["data-script-blocking-disabled"]) && $attributes["data-script-blocking-disabled"] !== "true")){
                        $script->setAttribute('type', "text/plain");
                    }
                }
            }
            if(!empty($serviceIdentifier)){
                $script->setAttribute('data-service', htmlentities($serviceIdentifier, ENT_QUOTES, 'UTF-8'));
                $script->setAttribute('type', "text/plain");
            }
        }

        return $dom->saveHTML($dom);
    }

    /**
     * Find and replace a iframe and override it with a Div to Inject iFrameManager in Frontend
     *
     * @param string $html
     * @param string $databaseRow
     * @param array $extensionConfiguration
     * @return string
     */
    public function overrideIframes($html,$databaseRow,$extensionConfiguration): string
    {

        if(!$this->isHTML($html)){
            return $html;
        }

        $html5 = new HTML5(['disable_html_ns' => true]);
        $dom = $html5->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $iframes = $xpath->query('//iframe');

        foreach ($iframes as $iframe) {
            $attributes = array();
            foreach ($iframe->attributes as $attr) {
                //Validate and sanitize attribute values
                //$attrValue = htmlentities($attr->value, ENT_NOQUOTES, 'UTF-8'); Removed because GET Parameter in Iframes are Quoted and Failed to Load
                $attributes[$attr->name] = $attr->value;
            }

            if(empty($attributes["src"])) {
                //Ignore inline Scripts without Source
                continue;
            }


            // if "unknown" as service, it will be a empty black box
            $serviceIdentifier = $this->classifyContent($attributes["src"]);

            if(empty($serviceIdentifier)){
                if(intval($extensionConfiguration["scriptBlocking"]) === 1){
                    //Script Blocking is enabled so Block all Scripts and Iframes
                    $this->scriptBlocker($iframe,$dom);
                    //$iframe->parentNode->replaceChild($div, $iframe);
                }
            }else{
                $inlineStyle = '';
                if (isset($attributes["height"])) {
                    $inlineStyle .= strpos($attributes["height"], 'px') !== false ? "height:{$attributes["height"]}; " : "height:{$attributes["height"]}px; ";
                }
                if (isset($attributes["width"])) {
                    $inlineStyle .= strpos($attributes["width"], 'px') !== false ? "width:{$attributes["width"]}; " : "width:{$attributes["width"]}px; ";
                }
                $inlineStyle = isset($attributes["style"]) ? htmlentities($attributes["style"], ENT_QUOTES, 'UTF-8') . $inlineStyle : $inlineStyle;

                // Create new div element with sanitized attributes
                $div = $dom->createElement('div');
                $div->setAttribute('style', $inlineStyle);
                $div->setAttribute('data-service', htmlentities($serviceIdentifier, ENT_QUOTES, 'UTF-8'));
                $div->setAttribute('data-id', $attributes["src"]);
                $div->setAttribute('data-autoscale', "");
                // Replace iframe element with new div element
                $iframe->parentNode->replaceChild($div, $iframe);
            }
        }

        return $dom->saveHTML($dom);
    }

    /**
     * Classify Content by Searching for Iframes and Scripts get URLs and find the Service, if not Found Return false
     *
     * @param string providerURL
     * @return mixed
     */
    public function classifyContent($providerURL)
    {

        /** @var ClassifyContentEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ClassifyContentEvent($providerURL)
        );
        if(!empty($event)){
            $serviceIdentifierFromPSR14 = $event->getServiceIdentifier();
            if(!empty($serviceIdentifierFromPSR14)){
                return $serviceIdentifierFromPSR14;
            }
        }


        /* @deprecated Call the hook classifyContent */
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/cf-cookiemanager']['classifyContent'] ?? [] as $_funcRef) {
            $params = ["providerURL"=>$providerURL];
            $test =   GeneralUtility::callUserFunction($_funcRef, $params, $this);
            if(!empty($test)){
                return $test;
            }
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_cfcookiemanager_domain_model_cookieservice');
        $queryBuilder->select('provider', 'identifier')
            ->from('tx_cfcookiemanager_domain_model_cookieservice', 'service')
            ->innerJoin(
                'service',
                'tx_cfcookiemanager_cookiecartegories_cookieservice_mm',
                'mm',
                $queryBuilder->expr()->eq('mm.uid_foreign', 'service.uid')
            )
            ->where(
                $queryBuilder->expr()->isNotNull('mm.uid_local')
            );
        $servicesDB = $queryBuilder->executeQuery()->fetchAllAssociative();
        foreach ($servicesDB as $service) {
            if (!empty($service["provider"])) {
                $providers = explode(",", $service["provider"]);
                foreach ($providers as $provider) {
                    if (str_contains($providerURL, $provider)) {
                        //Content Blocker Found a Match
                        //IF FORCE BLOCK RETURN NOW.
                        //DebuggerUtility::var_dump($service["identifier"]);
                        return $service["identifier"];
                    }
                }
            }
        }

        return  false;
    }

    /**
     * This function renders the Scriptblocker template using Fluid StandaloneView and returns the HTML output.
     * @todo use this function to render the Consent Themes, check compatibility with the current implementation
     *
     * @param array $variables An associative array of variables to be assigned to the template.
     * @return string The rendered HTML output.
     */
    public function getTemplateHtml(array $variables = array()) {
        /** @var \TYPO3\CMS\Fluid\View\StandaloneView $tempView */
        $tempView = GeneralUtility::makeInstance(\TYPO3\CMS\Fluid\View\StandaloneView::class);

        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('cf_cookiemanager');
        $templateRootPath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName("EXT:cf_cookiemanager/Resources/Static/scriptblocker.html");

        if(!empty($extensionConfiguration["CF_SCRIPTBLOCKER"]) && file_exists(\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::resolvePackagePath($extensionConfiguration["CF_SCRIPTBLOCKER"]))) {
            $templateRootPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::resolvePackagePath($extensionConfiguration["CF_SCRIPTBLOCKER"]);
        }

        $tempView->setTemplatePathAndFilename($templateRootPath);

        $tempView->assignMultiple($variables);
        $tempHtml = $tempView->render();

        return $tempHtml;
    }

    /**
     * Prevents the loading of content such as iframes and scripts from third-party sources, can be Disabled by adding a Data Atribute to the Script or Iframe (data-script-blocking-disabled="true")
     *
     * @param \DOMElement $domElement The HTML content to be checked.
     * @return void|false The "modified" HTML content or an error message if the content was blocked.
     */
    public function scriptBlocker($domElement,$doc){


        if(!empty($domElement->getAttribute("src"))) {
            $iframe_host = parse_url($domElement->getAttribute("src"), PHP_URL_HOST);
            $scriptBlockingTag = $domElement->getAttribute('data-script-blocking-disabled');
            if(!empty($scriptBlockingTag) && $scriptBlockingTag == "true"){
                return false;
            }
            $current_host = $_SERVER['HTTP_HOST'];
            if($iframe_host !== $current_host){
                $div = $doc->createDocumentFragment();
                $div->appendXML($this->getTemplateHtml(["host"=>$iframe_host,"src"=>$domElement->getAttribute("src")]));

                // Replace iframe element with new div element
                $domElement->parentNode->replaceChild($div, $domElement);
            }
        }
    }
    public function scriptBlockerRegex($domElement,$dom,$content){



        if(!empty($domElement->getAttribute("src"))) {
            $iframe_host = parse_url($domElement->getAttribute("src"), PHP_URL_HOST);
            $scriptBlockingTag = $domElement->getAttribute('data-script-blocking-disabled');
            if(!empty($scriptBlockingTag) && $scriptBlockingTag == "true"){
                return $content; //Return the same content, because the script is enabled by data tag
            }
            $current_host = $_SERVER['HTTP_HOST'];
            if($iframe_host !== $current_host){
                $div = $dom->createDocumentFragment();
                $div->appendXML($this->getTemplateHtml(["host"=>$iframe_host,"src"=>$domElement->getAttribute("src")]));

                $regex = '/<iframe[^>]*src=["\']' . preg_quote($domElement->getAttribute("src"), '/') . '["\'][^>]*><\/iframe>/i';
                // Replace the current iframe with the replacement string
                $content = preg_replace($regex, $dom->saveHtml($div), $content);
            }
        }
        return $content;
    }

    /**
     * Main Hook for render Function to Classify and Protect Output Content from CMS
     *
     * @param string $content
     * @param array $extensionConfiguration
     * @return string
     */
    public function cfHook($content, $extensionConfiguration) : string
    {
        if(!empty($extensionConfiguration["scriptReplaceByRegex"])){
            //Experimental way
            $newContent = $this->replaceIframes($content,"",$extensionConfiguration);
            $newContent = $this->replaceScript($newContent,"",$extensionConfiguration);
        }else{
            //legacy way
            $newContent = $this->overrideIframes($content,"",$extensionConfiguration);
            $newContent = $this->overrideScript($newContent,"",$extensionConfiguration);
        }
        return $newContent;
    }



    /**
     *  Experimental Function to replace Iframes with Divs
     *  The issue is/was that every HTML parser alters the HTML in a way that doesn't match the original. Sometimes the doctype is missing, sometimes closing tags are added that shouldn't be there, and SVG also causes problems, or attributes are completed.
     *  I'm already considering approaching the entire thing differently by not saving the DOM anymore. Instead, I would temporarily read the real DOM to find elements more easily, and replace the HTML directly in the real DOM by using regex.
     */
    public function replaceIframes($content, $database, $extensionConfiguration) : string
    {

        if(!$this->isHTML($content)){
            return $content;
        }

        $html5 = new HTML5(['disable_html_ns' => true]);
        $dom = $html5->loadHTML($content);
        $xpath = new \DOMXPath($dom);
        $iframes = $xpath->query('//iframe');
        foreach ($iframes as $iframe) {

            $attributes = array();
            foreach ($iframe->attributes as $attr) {
                //Validate and sanitize attribute values
                //$attrValue = htmlentities($attr->value, ENT_NOQUOTES, 'UTF-8'); Removed because GET Parameter in Iframes are Quoted and Failed to Load
                $attributes[$attr->name] = $attr->value;
            }
            if(empty($attributes["src"])) {
                //Ignore inline Scripts without Source
                continue;
            }

            // if "unknown" as service, it will be a empty black box
            $serviceIdentifier = $this->classifyContent($attributes["src"]);

            if(empty($serviceIdentifier)){
                if(intval($extensionConfiguration["scriptBlocking"]) === 1){
                    //Script Blocking is enabled so Block all Scripts and Iframes
                    $content = $this->scriptBlockerRegex($iframe,$dom,$content);

                    //$iframe->parentNode->replaceChild($div, $iframe);
                }
            }else{
                $inlineStyle = '';
                if (isset($attributes["height"])) {
                    $inlineStyle .= strpos($attributes["height"], 'px') !== false ? "height:{$attributes["height"]}; " : "height:{$attributes["height"]}px; ";
                }
                if (isset($attributes["width"])) {
                    $inlineStyle .= strpos($attributes["width"], 'px') !== false ? "width:{$attributes["width"]}; " : "width:{$attributes["width"]}px; ";
                }
                $inlineStyle = isset($attributes["style"]) ? htmlentities($attributes["style"], ENT_QUOTES, 'UTF-8') . $inlineStyle : $inlineStyle;

                // Create new div element with sanitized attributes
                $div = $dom->createElement('div');
                $div->setAttribute('style', $inlineStyle);
                $div->setAttribute('data-service', htmlentities($serviceIdentifier, ENT_QUOTES, 'UTF-8'));
                $div->setAttribute('data-id', $attributes["src"]);
                $div->setAttribute('data-autoscale', "");

                //parse url and get host name
                $completeUrlWithoutParameters = parse_url($attributes["src"], PHP_URL_HOST) . parse_url($attributes["src"], PHP_URL_PATH);
                // $regex = '/<iframe[^>]*src=["\']' . preg_quote($attributes["src"], '/') . '["\'][^>]*><\/iframe>/is';
                $regex = '/<iframe[^>]*src=["\'].*' . preg_quote($completeUrlWithoutParameters,'/') . '.*["\'][^>]*><\/iframe>/i';
                // Replace the current iframe with the replacement string
                $content = preg_replace($regex, $dom->saveHTML($div), $content);
            }
        }

        return $content;
    }

    /**
     * add Attribute in HTML Tag...
     *
     * for Ex:- $htmlStr = <a href="https://coding-freaks.com">https://coding-freaks.com/</a> , $tagName = a, $attributeName = target, $attributevalue = _blank
     * output will :- <a href="https://coding-freaks.com" target="_blank">coding-freaks.com</a>
     *
     * then above $htmlStr = #above output, $tagName = a, $attributeName = style, $attributevalue = color:red;
     * output will :- <a href="https://coding-freaks.com" target="_blank" style="color:red;">coding-freaks.com</a>
     *
     * @param string $htmlStr // html string
     * @param string $tagname // html tag name
     * @param string $attributeName // html tag attribute name like class, id, style etc...
     * @param string $attributeValue // value of attribute like, classname, idname, style-property etc...
     *
     * @return string
     */
    public function addHtmlAttribute_in_HTML_Tag($htmlStr, $tagname, $attributeName, $attributeValue): string
    {
        /** if html tag attribute does not exist then add it ... */
        if (!preg_match("~<$tagname\s.*?$attributeName=([\'\"])~i", $htmlStr)) {
            $htmlStr = preg_replace('/(<' . $tagname . '\b[^><]*)>/i', '$1 ' . $attributeName . '="' . $attributeValue . '">', $htmlStr, 1);
        } else {
            // If the attribute already exists, replace its value
            $htmlStr = preg_replace("~(<$tagname\s.*?$attributeName=)([\'\"])(.*?)([\'\"])~i", '$1$2' . $attributeValue . '$4', $htmlStr);
        }
        return $htmlStr;
    }


    public function replaceScript($content, $database, $extensionConfiguration) : string
    {
        if(!$this->isHTML($content)){
            return $content;
        }

        $html5 = new HTML5(['disable_html_ns' => true]);
        $dom = $html5->loadHTML($content);
        $xpath = new \DOMXPath($dom);
        $scripts = $xpath->query('//script');

        foreach ($scripts as $script) {
            $attributes = array();
            foreach ($script->attributes as $attr) {
                $attributes[$attr->name] = $attr->value;
            }

            if(empty($attributes["src"])) {
                continue;
            }

            $serviceIdentifier = $this->classifyContent($attributes["src"]);

            // Parse the URL to ignore the GET parameters
            if(!empty(parse_url($attributes["src"], PHP_URL_HOST) )){
                $completeUrlWithoutParameters = parse_url($attributes["src"], PHP_URL_HOST) . parse_url($attributes["src"], PHP_URL_PATH);
                $scriptRegexPattern = '/<script(?![^>]*data-service)([^>]*src=["\'].*' . preg_quote($completeUrlWithoutParameters, '/') . '.*["\'][^>]*>.*?<\/script>)/i';

            }else{
                $scriptRegexPattern = '/<script(?![^>]*data-service)([^>]*src=["\'].*' . preg_quote($attributes["src"], '/') . '.*["\'][^>]*>.*?<\/script>)/i';

            }

            if(empty($serviceIdentifier)){
                if(intval($extensionConfiguration["scriptBlocking"]) === 1){
                    //Should we use Templates here? or just remove the script tag?
                    $content = preg_replace($scriptRegexPattern, '', $content);
                }
            } else {
                // Get the "original" script tag from the DOM parser, not 100% SAVE!
                //$originalScriptTag = $dom->saveHTML($script);

                // Get the original script tag from the content String by Regex
                preg_match($scriptRegexPattern, $content, $matches);
                if(empty($matches)){
                    //No Match found, this script is found, but can not be replaced by regex, trigger warning and continue
                    continue;
                }

                $originalScriptTag = $matches[0];
                // Add or modify the 'type' and 'data-service' attributes
                $modifiedScriptTag = $this->addHtmlAttribute_in_HTML_Tag($originalScriptTag, 'script', 'type', 'text/plain');
                $modifiedScriptTag = $this->addHtmlAttribute_in_HTML_Tag($modifiedScriptTag, 'script', 'data-service', htmlentities($serviceIdentifier, ENT_QUOTES, 'UTF-8'));

                // Replace the original script tag with the modified script tag in the content
                $content = preg_replace($scriptRegexPattern, $modifiedScriptTag, $content, 1);
            }
        }

        return $content;
    }

}
