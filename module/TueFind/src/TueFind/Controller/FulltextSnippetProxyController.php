<?php
/**
 * Proxy Controller Module
 *
 * @category    TueFind
 * @author      Johannes Riedl <johannes.riedl@uni-tuebingen.de>
 * @copyright   2019 Universtitätsbibliothek Tübingen
 */
namespace TueFind\Controller;

use VuFind\Exception\Forbidden as ForbiddenException;
use Elasticsearch\ClientBuilder;
use \Exception as Exception;
use Zend\Log\Logger as Logger;
use Zend\View\Model\JsonModel;


/**
 * Proxy for Fulltext Snippets in Elasticsearch
 * @package  Controller
 */

class FulltextSnippetProxyController extends \VuFind\Controller\AbstractBase
{

    protected $base_url; //Elasticsearch host and port (host:port)
    protected $index; //Elasticsearch index
    protected $page_index; //Elasticssearch index with single HTML pages
    protected $es; // Elasticsearch interface
    protected $logger;
    protected $configLoader;
    protected $maxSnippets = 5;
    const FIELD = 'full_text';
    const DOCUMENT_ID = 'id';
    const highlightStartTag = '<span class="highlight">';
    const highlightEndTag = '</span>';
    const fulltextsnippetIni = 'fulltextsnippet';
    const MAX_SNIPPETS_DEFAULT = 3;
    const MAX_SNIPPETS_VERBOSE = 1000;
    const PHRASE_LIMIT = 10000000;
    const FRAGMENT_SIZE_DEFAULT = 300;
    const FRAGMENT_SIZE_VERBOSE = 700;
    const ORDER_DEFAULT = 'none';
    const ORDER_VERBOSE = 'score';


    public function __construct(\Elasticsearch\ClientBuilder $builder, \VuFind\Log\Logger $logger, \VuFind\Config\PluginManager $configLoader) {
        $this->logger = $logger;
        $this->configLoader = $configLoader;
        $config = $configLoader->get($this->getFulltextSnippetIni());
        $this->base_url = isset($config->Elasticsearch->base_url) ? $config->Elasticsearch->base_url : 'localhost:9200';
        $this->index = isset($config->Elasticsearch->index) ? $config->Elasticsearch->index : 'full_text_cache';
        $this->page_index = isset($config->Elasticsearch->page_index) ? $config->Elasticsearch->page_index : 'full_text_cache_html';
        $this->es = $builder::create()->setHosts([$this->base_url])->build();
    }


    protected function getFulltextSnippetIni() {
        return self::fulltextsnippetIni;
    }


    protected function getQueryParams($doc_id, $search_query, $verbose, $paged_results) {
         $is_phrase_query = \TueFind\Utility::isSurroundedByQuotes($search_query);
         $this->maxSnippets = $verbose ? self::MAX_SNIPPETS_VERBOSE : self::MAX_SNIPPETS_DEFAULT;
         $index = $paged_results ? $this->page_index : $this->index;
         $params = [
             'index' => $index,
             'body' => [
                 '_source' => $paged_results ? [ "page" ] : false,
                 'size' => '100',
                 'query' => [
                     'bool' => [
                           'must' => [
                               [ $is_phrase_query ? 'match_phrase' : 'match' => [ self::FIELD => $search_query ] ],
                               [ 'match' => [ self::DOCUMENT_ID => $doc_id ] ]
                           ]
                      ]
                 ],
                 'highlight' => [
                     'fields' => [
                          self::FIELD => [
                               'type' => 'unified',
                               'fragment_size' => $verbose ? self::FRAGMENT_SIZE_VERBOSE : self::FRAGMENT_SIZE_DEFAULT,
                               'phrase_limit' => self::PHRASE_LIMIT,
                               'number_of_fragments' => $this->maxSnippets,
                               'order' => $verbose ? self::ORDER_VERBOSE : self::ORDER_DEFAULT,
                           ]
                      ]
                 ]
             ]
        ];
        return $params;
    }


    protected function getFulltext($doc_id, $search_query, $verbose) {
        // Is this an ordinary query or a phrase query (surrounded by quotes) ?
        $params = $this->getQueryParams($doc_id, $search_query, $verbose, false /*return paged results*/);
        $response = $this->es->search($params);
        $snippets = $this->extractSnippets($response);
        if ($snippets == false)
            return false;
        $results['snippets'] = $snippets['snippets'];
        return $results;
    }


    protected function getPagedAndFormattedFulltext($doc_id, $search_query, $verbose) {
        $params = $this->getQueryParams($doc_id, $search_query, $verbose, true);
        $response = $this->es->search($params);
        $snippets = $this->extractSnippets($response);
        if ($snippets == false)
            return false;
        $results['snippets'] = $snippets['snippets'];
        return $results;
    }


    protected function extractStyle($response) {
        $style = <<<EOT
<style type="text/css">
/*<![CDATA[*/
<!--
        p {margin: 0; padding: 0;}      .ft00{font-size:10px;font-family:Helvetica;color:#dcdcdc;}
        .ft01{font-size:11px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
        .ft02{font-size:7px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
        .ft03{font-size:11px;font-family:ILDCPF+StempelGaramond-Italic;color:#000000;}
        .ft04{font-size:10px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
        .ft05{font-size:6px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
        .ft06{font-size:11px;line-height:17px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
        .ft07{font-size:11px;line-height:17px;font-family:ILDCPF+StempelGaramond-Italic;color:#000000;}
        .ft08{font-size:10px;line-height:14px;font-family:ILDDBH+StempelGaramond-Roman;color:#000000;}
-->
/*]]>*/
</style>
EOT;
       return $style;

    }




    protected function extractSnippets($response) {
        $top_level_hits = [];
        $hits = [];
        $highlight_results = [];
        if (empty($response))
            return false;
        if (array_key_exists('hits', $response))
            $top_level_hits = $response['hits'];
        if (empty($top_level_hits))
            return false;
        //second order hits
        if (array_key_exists('hits', $top_level_hits))
           $hits = $top_level_hits['hits'];
        if (empty($top_level_hits))
           return false;

        $snippets = [];
        $pages = [];
        if (count($hits) > $this->maxSnippets)
            $hits = array_slice($hits, 0, $this->maxSnippets);
        foreach ($hits as $hit) {
            if (array_key_exists('highlight', $hit))
                $highlight_results = $hit['highlight'][self::FIELD];
            if (count($highlight_results) > $this->maxSnippets);
                $highlight_results = array_slice($highlight_results, 0, $this->maxSnippets);
            foreach ($highlight_results as $highlight_result) {
                array_push($snippets, [ 'snippet' =>  $highlight_result, 'page' => $hit['_source']['page'], 'style' => $this->extractStyle($hit)]);
            }
        }
        if (empty($snippets))
            return false;

        $results['snippets'] =  $this->formatHighlighting($snippets);
        return $results;
    }


    protected function formatHighlighting($snippets) {
        $formatted_snippets = [];
        foreach ($snippets as $snippet) {
            //$snippet = '...' . $snippet . '...';
            $snippet = preg_replace('/(<[^>]+) style=[\\s]*".*?"/i', '$1', $snippet); //remove styles with absolute positions
            array_push($formatted_snippets, str_replace(['<em>', '</em>'], [self::highlightStartTag, self::highlightEndTag], $snippet));
            //array_push($formatted_snippets, $snippet);
        }
        return $formatted_snippets;
    }


    public function loadAction() : JsonModel
    {
        $query = $this->getRequest()->getUri()->getQuery();
        $parameters = [];
        parse_str($query, $parameters);
        $doc_id = $parameters['doc_id'];
        if (empty($doc_id))
            return new JsonModel([
               'status' => 'EMPTY DOC_ID'
                ]);
        $search_query = $parameters['search_query'];
        if (empty($search_query))
            return new JsonModel([
                'status' => 'EMPTY QUERY'
                ]);
        $verbose = isset($parameters['verbose']) && $parameters['verbose'] == '1' ? true : false;
        $snippets = $this->getPagedAndFormattedFulltext($doc_id, $search_query, $verbose);
        if (empty($snippets))
            return new JsonModel([
                 'status' => 'NO RESULTS'
                ]);

        return new JsonModel([
               'status' => 'SUCCESS',
               'snippets' => $snippets['snippets']
               ]);
    }
}
