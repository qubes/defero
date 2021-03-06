<?php
/**
 * Created by PhpStorm.
 * User: tom.kay
 * Date: 25/09/13
 * Time: 10:54
 */

namespace Qubes\Defero\Applications\Defero\Controllers;

use Cubex\Facade\Redirect;
use Cubex\Foundation\Config\Config;
use Cubex\Foundation\Container;
use Cubex\Routing\StdRoute;
use Qubes\Defero\Applications\Defero\Defero;
use Qubes\Defero\Applications\Defero\Forms\CampaignMessageForm;
use Qubes\Defero\Applications\Defero\Views\Campaigns\CampaignMessageView;
use Qubes\Defero\Components\Campaign\Mappers\Campaign;

class CampaignMessageController extends DeferoController
{
  protected $_message;
  protected $_lookup;

  public function renderIndex()
  {
    $config    = $this->config('i18n');
    $languages = $config->getArr('languages');
    return new CampaignMessageView($this->_getMessage(), $languages);
  }

  public function postIndex()
  {
    $postData = $this->request()->postVariables();
    $form     = new CampaignMessageForm('campaign_message');
    $form->bindMapper($this->_getMessage());
    $form->hydrate($postData);

    if($form->isValid() && $form->csrfCheck(true))
    {
      $form->saveChanges();

      $languageIsActive = idx($postData, 'active') != null;
      $campaign         = new Campaign($this->getInt('id'));
      $language         = $this->getStr('hl', 'en');
      if($campaign->availableLanguages == null && $languageIsActive)
      {
        $campaign->availableLanguages = [$language => $language];
      }
      else
      {
        $availableLanguages = (array)$campaign->availableLanguages;
        if($languageIsActive)
        {
          $availableLanguages[$language] = $language;
        }
        else
        {
          unset($availableLanguages[$language]);
        }
        $campaign->availableLanguages = $availableLanguages;
      }
      $campaign->saveChanges();
    }
    return $this->renderIndex();
  }

  protected function _getMessage()
  {
    if(!$this->_message)
    {
      $campaign       = new Campaign($this->getInt('id'));
      $this->_message = $campaign->message();

      $currentLanguage = $this->getStr('hl');
      if($currentLanguage)
      {
        $this->_message->setLanguage($currentLanguage);
      }
      $this->_message->reload();
    }
    return $this->_message;
  }

  public function renderTranslate()
  {
    $campaign = new Campaign($this->getInt('id'));
    $message  = $campaign->message();
    $message->reload();

    $subject   = $this->translateString($message->subject);
    $plainText = $this->translateString($message->plainText);

    $language = $this->getStr('hl');

    $htmlContent = $message->htmlContent;
    while($this->translateBlock($htmlContent))
    {
    }

    $message->setLanguage($language);
    $message->reload();
    $message->subject     = $subject;
    $message->plainText   = $plainText;
    $message->htmlContent = $htmlContent;
    $message->saveChanges();

    if($campaign->availableLanguages == null)
    {
      $campaign->availableLanguages = [$language => $language];
    }
    else
    {
      $availableLanguages            = (array)$campaign->availableLanguages;
      $availableLanguages[$language] = $language;
      $campaign->availableLanguages  = $availableLanguages;
    }
    $campaign->saveChanges();

    return Redirect::to(
      '/campaigns/' . $this->getStr(
        'id'
      ) . '/message/' . $this->getStr('hl')
    );
  }

  public function prepTextForTranslate($string)
  {
    $pattern = '/{[\!\?]([^{}]*|(?R))*}/i';
    while(preg_match($pattern, $string, $matches))
    {
      $match                      = $matches[0];
      $this->_lookup[md5($match)] = $match;

      $string = str_replace($match, md5($match), $string);
    }
    return nl2br($string); //just because google drops new lines
  }

  private function _reversePlaceHolders($string)
  {
    if($this->_lookup != null)
    {
      foreach($this->_lookup as $key => $replace)
      {
        $string = str_replace($key, $replace, $string);
      }
    }
    return $string;
  }

  public function reversePlaceHolders($string)
  {
    $reversed = $this->_reversePlaceHolders($string);
    while($string != $reversed)
    {
      $string   = $reversed;
      $reversed = $this->_reversePlaceHolders($reversed);
    }

    //change <br> to new line
    $reversed = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $reversed);
    return $reversed;
  }

  public function translateBlock(&$string)
  {
    $startString = $string;

    // start, end
    $start = strpos($string, '{{');
    if($start === false)
    {
      return false;
    }
    $end = strpos($string, '}}', $start) + 2;
    if($end === false)
    {
      return false;
    }
    $match = substr($string, $start, $end - $start);

    $replace = $this->translateString(substr($match, 2, -2));

    // replace
    $string = str_replace($match, $replace, $string);

    return $string !== $startString;
  }

  public function translateString($string)
  {
    if(!empty($string))
    {
      $this->prepTextForTranslate($string);
      // translate
      $config         = Container::config()->get("i18n", new Config());
      $translateClass = $config->getStr("translator", null);
      if(!$translateClass)
      {
        throw new \Exception(
          'Missing \'translator\' in i18n section of the config'
        );
      }
      $translator     = new $translateClass();
      $translatedText = $translator->translate(
        $string,
        'en',
        $this->getStr('hl')
      );
      $string         = $this->reversePlaceHolders($translatedText);
    }

    return $string;
  }

  public function getRoutes()
  {
    return [
      new StdRoute('/:hl/translate', 'translate'),
      new StdRoute('/:hl', 'index'),
      new StdRoute('/', 'index')
    ];
  }
}
