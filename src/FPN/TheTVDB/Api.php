<?php

/*
 * This file is part of the TheTVDB.
 *
 * (c) 2010-2012 Fabien Pennequin <fabien@pennequin.me>
 * (c) 2012 Tobias Sjösten <tobias.sjosten@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FPN\TheTVDB;

use FPN\TheTVDB\HttpClient\HttpClientInterface;
use FPN\TheTVDB\Model\TvShow;
use FPN\TheTVDB\Model\Episode;
use FPN\TheTVDB\Model\Banner;

class Api
{
    protected $httpClient;
    protected $apiKey;

    protected $mirrorUrl = 'http://www.thetvdb.com/';
    protected $baseUrl;
    protected $baseKeyUrl;
    protected $baseImagesUrl;

    const UPDATES_DAY = 'day';
    const UPDATES_WEEK = 'week';
    const UPDATES_MONTH = 'month';
    const UPDATES_ALL = 'all';

    public function __construct(HttpClientInterface $httpClient, $apiKey, $mirrorUrl=null)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;

        if ($mirrorUrl) {
            $this->mirrorUrl = $mirrorUrl;
        }

        $this->baseUrl = $this->mirrorUrl.'api/';
        $this->baseKeyUrl = $this->baseUrl.$this->apiKey.'/';
        $this->baseImagesUrl = $this->mirrorUrl.'banners/';
    }

    public function getMirrorUrl()
    {
        return $this->mirrorUrl;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function getBaseUrlWithKey()
    {
        return $this->baseKeyUrl;
    }

    public function searchTvShow($name, $language=null)
    {
        $url = $this->baseUrl.'GetSeries.php?seriesname='.urlencode($name);
        if ($language) {
            $url .= '&language='.urlencode($language);
        }

        $data = array();
        $xml = @simplexml_load_string($this->httpClient->get($url));

        if ($xml) {
            foreach ($xml as $xmlSerie) {
                $data[] = $this->xmlToTvShow($xmlSerie);
            }
        }

        return $data;
    }

    public function getTvShow($tvshowId, $language='en')
    {
        $url = $this->baseKeyUrl.'series/'.$tvshowId.'/'.$language.'.xml';
        $xml = @simplexml_load_string($this->httpClient->get($url));

        return isset($xml->Series) ? $this->xmlToTvShow($xml->Series) : null;
    }

    public function getEpisode($episodeId, $language='en')
    {
        $url = $this->baseKeyUrl.'episodes/'.$episodeId.'/'.$language.'.xml';
        $xml = @simplexml_load_string($this->httpClient->get($url));

        return isset($xml->Episode) ? $this->xmlToEpisode($xml->Episode) : null;
    }

    public function getTvShowAndEpisodes($tvshowId, $language='en')
    {
        $url = $this->baseKeyUrl.'series/'.$tvshowId.'/all/'.$language.'.xml';
        $xml = @simplexml_load_string($this->httpClient->get($url));

        if (isset($xml->Series)) {
            $tvshow = $this->xmlToTvShow($xml->Series);

            $episodes = array();
            if (isset($xml->Episode)) {
                foreach ($xml->Episode as $xmlEpisode) {
                    $episodes[] = $this->xmlToEpisode($xmlEpisode);
                }
            }

            return array('tvshow' => $tvshow, 'episodes' => $episodes);
        }
    }

    public function getUpdates($timeframe = self::UPDATES_DAY)
    {
        switch ($timeframe) {
            case self::UPDATES_DAY:
            case self::UPDATES_WEEK:
            case self::UPDATES_MONTH:
            case self::UPDATES_ALL:
                break;
            default:
                throw new \Exception($span.' is not a valid time span');
        }

        $url = $this->baseKeyUrl.'updates/updates_'.$timeframe.'.xml';
        $xml = @simplexml_load_string($this->httpClient->get($url));

        $data = array(
            'tvshows' => array(),
            'episodes' => array(),
            'banners' => array(),
        );

        foreach ($xml->Series as $series) {
            $data['tvshows'][] = $this->xmlToTvShow($series);
        }
        foreach ($xml->Episode as $episode) {
            $data['episodes'][] = $this->xmlToEpisode($episode);
        }
        foreach ($xml->Banner as $banner) {
            $data['banners'][] = $this->xmlToBanner($banner);
        }

        return $data;
    }

    public function getBanners($showId)
    {
        $url = $this->baseKeyUrl.'series/'.$showId.'/banners.xml';
        $xml = @simplexml_load_string($this->httpClient->get($url));

        $data = array();

        if ($xml) {
            foreach ($xml as $xmlBanner) {
                $data[] = $this->xmlToBanner($xmlBanner);
            }
        }

        return $data;
    }

    protected function xmlToTvShow(\SimpleXmlElement $element)
    {
        $tvshow = new TvShow();
        $tvshow->fromArray(array(
            'id'            => (int)$element->id,
            'name'          => (string)$element->SeriesName,
            'overview'      => (string)$element->Overview,
            'network'       => isset($element->Network) ? (string)$element->Network : null,
            'language'      => isset($element->language) ? (string)$element->language : (isset($element->Language) ? (string)$element->Language : null),
            'genres'        => isset($element->Genre) ? explode('|', trim($element->Genre, '|')) : array(),

            'rating'        => isset($element->Rating) ? (float)$element->Rating : null,
            'ratingcount'   => isset($element->RatingCount) ? (int)$element->RatingCount : null,

            'statut'        => isset($element->Status) ? (string)$element->Status : null,

            'firstAired'    => (string)$element->FirstAired
                ? new \DateTime($element->FirstAired)
                : null,

            'theTvDbId'     => isset($element->seriesid) ? (int)$element->seriesid : (int)$element->id,
            'imdbId'        => (string)$element->IMDB_ID,
            'zap2itId'      => (string)$element->zap2it_id,

            'bannerUrl'     => (isset($element->banner) && (string)$element->banner) ? $this->baseImagesUrl.$element->banner : null,
            'posterUrl'     => (isset($element->poster) && (string)$element->poster) ? $this->baseImagesUrl.$element->poster : null,
            'fanartUrl'     => (isset($element->fanart) && (string)$element->fanart) ? $this->baseImagesUrl.$element->fanart : null,

            'actors'        => (isset($element->Actors) && (string)$element->Actors) ? explode('|', trim($element->Actors, '|')) : array(),
        ));

        return $tvshow;
    }

    protected function xmlToEpisode(\SimpleXmlElement $element)
    {
        $episode = new Episode();
        $episode->fromArray(array(
            'id'            => (int)$element->id,

            'tvshowId'      => (int)$element->seriesid,
            'seasonId'      => (int)$element->seasonid,

            'episodeNumber' => (int)$element->EpisodeNumber,
            'seasonNumber'  => (int)$element->SeasonNumber,

            'name'          => (string)$element->EpisodeName,
            'overview'      => (string)$element->Overview,
            'language'      => (string)$element->Language,

            'firstAired'    => (string)$element->FirstAired
                ? new \DateTime($element->FirstAired)
                : null,

            'image'         => (isset($element->filename) && (string)$element->filename) ? $this->baseImagesUrl.$element->filename : null,
        ));

        return $episode;
    }

    protected function xmlToBanner(\SimpleXmlElement $element)
    {
        $banner = new Banner();
        $banner->fromArray(array(
            'id'            => (int)$element->id,
            'language'      => (string)$element->Language,

            'bannerType'    => (string)$element->BannerType,
            'bannerSize'    => strpos($element->BannerType2, 'x') > 0 ? (string)$element->BannerType2 : null,
            'bannerUrl'     => $this->baseImagesUrl.$element->BannerPath,

            'thumbnailUrl'  => isset($element->ThumbnailPath) ? $this->baseImagesUrl.$element->ThumbnailPath : null,
        ));

        return $banner;
    }
}
