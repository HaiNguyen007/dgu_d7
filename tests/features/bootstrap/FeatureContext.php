<?php

use Behat\Behat\Context\Step\Given;
use Behat\Behat\Context\Step\Then;
use Behat\Behat\Event\FeatureEvent;
use Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ElementNotFoundException;
use Drupal\Component\Utility\Random;


/**
 * Some of our features need to run their scenarios sequentially
 * and we need a way to pass relevant data (like generated node id)
 * from one scenario to the next.  This class provides a simple
 * registry to pass data. This should be used only when absolutely
 * necessary as scenarios should be independent as often as possible.
 */
abstract class HackyDataRegistry {
  public static $data = array();
  public static function set($name, $value) {
    self::$data[$name] = $value;
  }
  public static function get($name) {
    $value = "";
    if (isset(self::$data[$name])) {
      $value = self::$data[$name];
    }
    if ($value === "") {
      $backtrace = debug_backtrace(FALSE, 2);
      $calling = $backtrace[1];
      if (array_key_exists('line', $calling) && array_key_exists('file', $calling)) {
        throw new PendingException(sprintf("Fix HackyDataRegistry accessing with unset key at %s:%d in %s.", $calling['file'], $calling['line'], $calling['function']));
      } else {
        // Disabled primarily for calls from AfterScenario for now due to too many errors.
        //throw new PendingException(sprintf("Fix HackyDataRegistry accessing with unset key in %s.", $calling['function']));
      }
    }
    return $value;
  }
  public static function keyExists($name) {
    if (isset(self::$data[$name])) {
      return TRUE;
    }
    return FALSE;
  }
}

class LocalDataRegistry {
  public $data = array();
  public function set($name, $value) {
    $this->data[$name] = $value;
  }
  public function get($name) {
    $value = "";
    if (isset($this->data[$name])) {
      $value = $this->data[$name];
    }
    return $value;
  }
}

// Require 3rd-party libraries here:
//
//   require_once 'PHPUnit/Autoload.php';
//   require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Features context.
 */
// class FeatureContext extends BehatContext
class FeatureContext extends Drupal\DrupalExtension\Context\DrupalContext
{
  protected $users = array();
  /**
   * Initializes context.
   * Every scenario gets its own context object.
   *
   * @param array $parameters context parameters (set them up through behat.yml)
   */
  public function __construct(array $parameters)
  {
    $this->dataRegistry = new LocalDataRegistry();
    $this->random = new Random();

    if (isset($parameters['drupal_users'])) {
      $this->drupal_users = $parameters['drupal_users'];
    }
    if (isset($parameters['email'])) {
      $this->email = $parameters['email'];
    }
    $this->mailAddresses = array();
    $this->mailMessages = array();
  }

  /**
   * @BeforeFeature
   */
  public static function prepare(FeatureEvent $event)
  {
    //$cmd = 'drush @standards.test ev \'$query = new EntityFieldQuery(); $result = $query->entityCondition("entity_type", "node")->propertyCondition("title", "Test ", "STARTS_WITH")->execute(); if (isset($result["node"])) {$nids = array_keys($result["node"]); foreach ($nids as $nid) {node_delete($nid);}}\'';
    //shell_exec($cmd);
  }

  /**
   * Hold the execution until the page is/resource are completely loaded OR timeout
   *
   * @Given /^I wait until the page (?:loads|is loaded)$/
   * @param object $callback
   *   The callback function that needs to be checked repeatedly
   */
  public function iWaitUntilThePageLoads($callback = null) {
    // Manual timeout in seconds
    $timeout = 60;
    // Default callback
    if (empty($callback)) {
      if ($this->getSession()->getDriver() instanceof Behat\Mink\Driver\GoutteDriver) {
        $callback = function($context) {
          // If the page is completely loaded and the footer text is found
          if(200 == $context->getSession()->getDriver()->getStatusCode()) {
            return true;
          }
          return false;
        };
      }
      else {
        // Convert $timeout value into milliseconds
        // document.readyState becomes 'complete' when the page is fully loaded
        $this->getSession()->wait($timeout*1000, "document.readyState == 'complete'");
        return;
      }
    }
    if (!is_callable($callback)) {
      throw new Exception('The given callback is invalid/doesn\'t exist');
    }
    // Try out the callback until $timeout is reached
    for ($i = 0, $limit = $timeout/2; $i < $limit; $i++) {
      if ($callback($this)) {
        return true;
      }
      // Try every 2 seconds
      sleep(2);
    }
    throw new Exception('The request is timed out');
  }


  /**
   * @Given /^I wait (\d+) second(?:s|)$/
   */
  public function iWaitSeconds($arg1) {
    sleep($arg1);
  }


  /**
   * @Given /^I should not be logged in$/
   */
  public function iShouldNotBeLoggedIn() {
    if ($this->loggedIn()) {
      return false;
    }
  }

  /**
   * Determine if the a user is already logged in.
   * Override DrupalContext::loggedIn() because we display logout link in the dropdown.
   */
  public function loggedIn() {
    $session = $this->getSession();
    $session->visit($this->locatePath('/user'));
    // If a logout link is found, we are logged in. While not perfect, this is
    // how Drupal SimpleTests currently work as well.
    $element = $session->getPage();
    sleep(1);
    return $element->findLink($this->getDrupalText('log_out'));
  }

  /**
   * Authenticates a user with password from configuration.
   *
   * @Given /^I am logged in as (?:|the )"([^"]*)"(?:| user)$/
   * @Given /^I log in as (?:|the )"([^"]*)"(?:| user)$/
   */
  public function iAmLoggedInAs($username) {
    $password = $this->drupal_users[$username];
    return $this->iAmLoggedInAsTheWithThePassword($username, $password);
  }

  /**
   * @Given /^I am logged in as the "([^"]*)" with the password "([^"]*)"$/
   * @Given /^I log in as the "([^"]*)" with the password "([^"]*)"$/
   */
  public function iAmLoggedInAsTheWithThePassword($username, $password) {
    return array (
      new Given("I fill in \"Username or e-mail address\" with \"$username\""),
      new Given("I fill in \"Password\" with \"$password\""),
      new Given("I press \"Log in\""),
    );
  }

  /**
   * @Given /^I am logged in as a user "([^"]*)" with the "([^"]*)" role$/
   */
  public function iAmLoggedInAsAUserWithTheRole($user_name, $role) {
    if (isset($user_name)) {

      // Check if a user with this user name and role is already logged in.
      if ($this->loggedIn() && $this->user && isset($this->user->role) && $this->user->role == $role && isset($this->user->name) && $this->user->name == $user_name) {
        return TRUE;
      }
      elseif (isset($this->users[$user_name])) {
        // Set previously used credentials as current.
        $this->user = $this->users[$user_name];
        // Login.
        $this->login();
        return TRUE;
      }
      // Create user.
      $user = (object) array(
        'name' => $user_name,
        'pass' => $this->random->name(16),
        'role' => $role,
      );
      $user->mail = $this->getMailAddress($user_name);

      try {
        // Create a new user.
        $this->getDriver()->userCreate($user);
      }
      catch (\Exception $e) {
        throw new Exception("Can't create user account.\n" . $e->getMessage());
        print_r($e);
      }

      $this->users[$user_name] = $this->user = $user;

      if ($role == 'authenticated user') {
        // Nothing to do.
      }
      else {
        $this->getDriver()->userAddRole($user, $role);
      }

      // Login.
      $this->login();

      return TRUE;
    }
  }

  /**
   * @Given /^I click RSS icon in "([^"]*)" column in "([^"]*)" row$/
   */
  public function iClickRssIconInColumnInRow($column, $row) {

    $row_element = $this->getSession()->getPage()->find('css', '.panel-display .row-' . $row);
    if (empty($row_element)) {
      throw new Exception(ucfirst($row) . ' row not found');
    }

    $column_element = $row_element->find('css', '.panel-col-' . $column);
    if (empty($column_element)) {
      throw new Exception(ucfirst($column) . ' column not found in '. ucfirst($row) . ' row');
    }

    $rss_icons = $column_element->findAll('css', '.rss-icon');
    if (empty($rss_icons)) {
      throw new Exception('No RSS icons found in the ' . $column . ' column in '. ucfirst($row) . ' row');
    }
    foreach ($rss_icons as $rss_icon) {
      if (!$rss_icon->isVisible()) {
        throw new Exception('RSS icon found in ' . ucfirst($column) . ' column in '. ucfirst($row) . ' row but it\'s not visible');
      }
      $rss_icon->click();
      return;
    }
    throw new Exception('RSS icon found in the ' . $column . ' column but it\'s not visible');
  }

  /**
   * @Given /^I click search icon$/
   */
  public function iClickSearchIcon() {

    $search_icons = $this->getSession()->getPage()->findAll('css', '#dgu-search-form .btn-default');
    if (empty($search_icons)) {
      throw new Exception('No search icons found');
    }
    foreach ($search_icons as $search_icon) {
      if (!$search_icon->isVisible()) {
        throw new Exception('Search icon found but it\'s not visible');
      }
      $search_icon->click();
      return;
    }

  }

  /**
   * @Given /^search result counter should match "([^"]*)"$/
   */
  public function searchResultCounterShouldMatch($regex) {
    // Search for counter on landing pages first.
    $search_counter = $this->getSession()->getPage()->find('css', '#dgu-search-form .right .right-inner');
    if (empty($search_counter)) {
      // If not found then search for counter on search page.
      $search_counter = $this->getSession()->getPage()->find('css', '.pane-dgu-search-info .result-info');
      if (empty($search_counter)) {
        throw new Exception('Search counter not found');
      }
    }
    $text = $search_counter->getText();
    preg_match('/' . $regex . '/i', $text, $match);

    if (!$search_counter->isVisible()) {
      throw new Exception('Search counter found but it\'s not visible');
    }
    elseif (empty($match)) {
      throw new Exception('Search counter found but it contains "' . $text . '" which doesn\'t match "' . $regex . '"');
    }
    return;
  }

  /**
   * @Given /^I fill in "([^"]*)" with random text$/
   */
  public function iFillInWithRandomText($label) {
    // A @Tranform would be more elegant.
    $randomString = strtolower($this->random->name(10));
    // Save this for later retrieval.
    HackyDataRegistry::set('random:' . $label, $randomString);
    $step = "I fill in \"$label\" with \"$randomString\"";
    return new Then($step);
  }


  /**
   * @Given /^I should not see the following <texts>$/
   */
  public function iShouldNotSeeTheFollowingTexts(TableNode $table) {
    $page = $this->getSession()->getPage();
    $table = $table->getHash();
    foreach ($table as $key => $value) {
      $text = $table[$key]['texts'];
      if(!$page->hasContent($text) === FALSE) {
        throw new Exception("The text '" . $text . "' was found");
      }
    }
  }

  /**
   * @Given /^I (?:should |)see the following <texts>$/
   */
  public function iShouldSeeTheFollowingTexts(TableNode $table) {
    $page = $this->getSession()->getPage();
    $messages = array();
    $failure_detected = FALSE;
    $table = $table->getHash();
    foreach ($table as $key => $value) {
      $text = $table[$key]['texts'];
      if($page->hasContent($text) === FALSE) {
        $messages[] = "FAILED: The text '" . $text . "' was not found";
        $failure_detected = TRUE;
      } else {
        $messages[] = "PASSED: '" . $text . "'";
      }
    }
    if ($failure_detected) {
      throw new Exception(implode("\n", $messages));
    }
  }

  /**
   * @Given /^I (?:should |)see the following <links>$/
   */
  public function iShouldSeeTheFollowingLinks(TableNode $table) {
    $page = $this->getSession()->getPage();
    $table = $table->getHash();
    foreach ($table as $key => $value) {
      $link = $table[$key]['links'];
      $result = $page->findLink($link);
      if(empty($result)) {
        throw new Exception("The link '" . $link . "' was not found");
      }
    }
  }

  /**
   * @Given /^I should not see the following <links>$/
   */
  public function iShouldNotSeeTheFollowingLinks(TableNode $table) {
    $page = $this->getSession()->getPage();
    $table = $table->getHash();
    foreach ($table as $key => $value) {
      $link = $table[$key]['links'];
      $result = $page->findLink($link);
      if(!empty($result)) {
        throw new Exception("The link '" . $link . "' was found");
      }
    }
  }

  /**
   * @Given /^I should see the following <breadcrumbs>$/
   */
  public function iShouldSeeTheFollowingBreadcrumbs(TableNode $breadcrumbs) {

    if (empty($breadcrumbs)) {
      throw new Exception('Breadcrumbs list passed to the function is empty.');
    }
    $breadcrumbs = array_keys($breadcrumbs->getRowsHash());

    $breadcrumbs_element = $this->getSession()->getPage()->find('css', '#breadcrumbs');
    if (empty($breadcrumbs_element)) {
      throw new Exception('Breadcrumbs not found.');
    }

    $items = $breadcrumbs_element->findAll('css', 'li');

    $home = array_shift($items);

    if ($home->getHtml() != 'Home') {
      throw new Exception('First breadcrumb is not the homepage.');
    }

    if (empty($items)) {
      throw new Exception('No breadcrumbs apart home has been found.');
    }

    $last_element = array_pop($items);
    $last_breadcrumb = array_pop($breadcrumbs);

    if ($last_element->getText() != $last_breadcrumb) {
      throw new Exception('Last breadcumb is not "' . $last_breadcrumb . '".');
    }
    elseif ($last_element->findLink($last_breadcrumb)) {
      throw new Exception('Last breadcumb "' . $last_breadcrumb . '" is a link.');
    }

    if (count($items) != count($breadcrumbs)) {
      $items_count = count($items);
      $breadcrumb_count = count($breadcrumbs);
      $symbol = $items_count > $breadcrumb_count ? 'less' : 'greater';
      throw new Exception('Nuber of breadcrumbs passed to the function is ' . $symbol . ' than number of breadcumbs found.');
    }

    foreach ($items as $key => $item) {
      if (!$item->findLink($breadcrumbs[$key])) {
        throw new Exception('Breadcrumb "' . $key + 1 . '" is not "' . $breadcrumbs[$key] . '".');
      };
    }

  }


  /**
   * @Given /^I should see "([^"]*)" block in "([^"]*)" column in "([^"]*)" row$/
   */
  public function iShouldSeeBlockInColumnInRow($block_title, $column, $row) {

    $row_element = $this->getSession()->getPage()->find('css', '.panel-display .row-' . $row);
    if (empty($row_element)) {
      throw new Exception(ucfirst($row) . ' row not found');
    }

    $column_element = $row_element->find('css', '.panel-col-' . $column);
    if (empty($column_element)) {
      throw new Exception(ucfirst($column) . ' column not found in '. ucfirst($row) . ' row');
    }

    $h2 = $column_element->findAll('css', '.block h2');
    if (empty($h2)) {
      throw new Exception('No blocks were found in the ' . $column . ' column in '. ucfirst($row) . ' row');
    }
    foreach ($h2 as $text) {
      if (trim($text->getText()) == $block_title) {
        if(!$text->isVisible()) {
          throw new Exception('Block "' . $block_title . '" found in ' . ucfirst($column) . ' column in '. ucfirst($row) . ' row but it\'s not visible');
        }
        return;
      }
    }
    throw new Exception('The block "' . $block_title . '" was not found in the ' . $column . ' column in '. ucfirst($row) . ' row');
  }

  /**
   * @Given /^I should see "([^"]*)" pane in "([^"]*)" column in "([^"]*)" row$/
   */
  public function iShouldSeePaneInColumnInRow($pane_title, $column, $row) {

    $row_element = $this->getSession()->getPage()->find('css', '.panel-display .row-' . $row);
    if (empty($row_element)) {
      throw new Exception(ucfirst($row) . ' row not found');
    }

    $column_element = $row_element->find('css', '.panel-col-' . $column);
    if (empty($column_element)) {
      throw new Exception(ucfirst($column) . ' column not found in '. ucfirst($row) . ' row');
    }

    $h2 = $column_element->findAll('css', '.panel-pane h2');
    if (empty($h2)) {
      throw new Exception('No panel panes were found in the ' . $column . ' column in '. ucfirst($row) . ' row');
    }
    foreach ($h2 as $text) {
      if (trim($text->getText()) == $pane_title) {
        if(!$text->isVisible()) {
          throw new Exception('Pane "' . $pane_title . '" found in ' . ucfirst($column) . ' column in '. ucfirst($row) . ' row but it\'s not visible');
        }
        return;
      }
    }
    throw new Exception('Panel pane "' . $pane_title . '" was not found in the ' . $column . ' column in '. ucfirst($row) . ' row');
  }

  /**
   * @Given /^I should not see "([^"]*)" pane in "([^"]*)" column in "([^"]*)" row$/
   */
  public function iShouldNotSeePaneInColumnInRow($pane_title, $column, $row) {

    $row_element = $this->getSession()->getPage()->find('css', '.panel-display .row-' . $row);
    if (empty($row_element)) {
      throw new Exception(ucfirst($row) . ' row not found');
    }

    $column_element = $row_element->find('css', '.panel-col-' . $column);
    if (empty($column_element)) {
      throw new Exception(ucfirst($column) . ' column not found in '. ucfirst($row) . ' row');
    }

    $h2 = $column_element->findAll('css', '.panel-pane h2');
    if (empty($h2)) {
      throw new Exception('No panel panes were found in the ' . $column . ' column in '. ucfirst($row) . ' row');
    }
    foreach ($h2 as $text) {
      if (trim($text->getText()) == $pane_title) {
        throw new Exception('Pane "' . $pane_title . '" found in ' . ucfirst($column) . ' column in '. ucfirst($row) . ' row');
      }
    }
    return;
  }


  /**
   * @Then /^I should see a message about created draft "([^"]*)"$/
   */
  public function iShouldSeeAMessageAboutCreatedDraft($content_type) {
    return $this->assertSuccessMessage("Your draft $content_type has been created. You can update it in My Drafts section.");
  }

  /**
   * @Then /^I should see a message about "([^"]*)" being submitted for moderation$/
   */
  public function iShouldSeeAMessageAboutBeingSubmittedForModeration($content_type) {
    return $this->assertSuccessMessage("Your $content_type has been updated and submitted for moderation. You can update it in Manage my content section.");
  }


  /**
   * Function to check if the field specified is outlined in red or not
   *
   * @Given /^the field "([^"]*)" should be outlined in red$/
   *
   * @param string $field
   *   The form field label to be checked.
   */
  public function theFieldShouldBeOutlinedInRed($field) {
    $page = $this->getSession()->getPage();
    // get the object of the field
    $formField = $page->findField($field);
    if (empty($formField)) {
      throw new Exception('The page does not have the field with label "' . $field . '"');
    }
    // get the 'class' attribute of the field
    $class = $formField->getAttribute("class");
    // we get one or more classes with space separated. Split them using space
    $class = explode(" ", $class);
    // if the field has 'error' class, then the field will be outlined with red
    if (!in_array("error", $class)) {
      throw new Exception('The field "' . $field . '" is not outlined with red');
    }
  }

  /**
   * Return email address for given user role.
   */
  protected function getMailAddress($user) {

    if(empty($this->mailAddresses[$user])) {
      $this->mailAddresses[$user] = $this->email['username'] . '+' . str_replace('_', '.', $user) . '.'  . $this->random->name(8) . '@'. $this->email['host'];
    }

    return $this->mailAddresses[$user];
  }

  /**
   * @Given /^I fill in "([^"]*)" with "([^"]*)" address$/
   */
  public function iFillInWithAddress($label, $user) {
    $mail_address = $this->getMailAddress($user);
    $step = "I fill in \"$label\" with \"$mail_address\"";
    return new Then($step);
  }

  /**
   * @Given /^the "([^"]*)" user received an email '([^']*)'$/
   */
  public function theUserReceivedAnEmail($user, $title) {
    sleep(3);
    $mail_address = $this->getMailAddress($user);
    $title = $this->fixStepArgument($title);

    $mbox = imap_open( $this->email['mailbox'], $mail_address,  $this->email['password']);

    $all = imap_check($mbox);

    $received = false;
    // Trying 100 times with three seconds pause
    for ($attempts = 0; $attempts++ < 100; ) {

      if ($all->Nmsgs) {
        foreach (imap_fetch_overview($mbox, "1:$all->Nmsgs") as $msg) {
          if ($msg->to == $mail_address && strpos($msg->subject, $title) !== FALSE) {
            $msg->body = imap_fetchbody($mbox, $msg->msgno, 1);
            // Consider if we start sending HTML emails.
            //$msg->body['html'] = imap_fetchbody($mbox, $msg->msgno, 2);
            $this->mailMessages[$user][] = $msg;
            imap_delete($mbox, $msg->msgno);
            $received = true;
            break 2;
          }
        }
      }
      sleep(5);
    }
    imap_close($mbox);
    // Throw Exception if message not found.
    if (!$received) {
      throw new \Exception('Email "' . $title . '" to "' . $mail_address . '" not received.');
    }
  }

  /**
   * @Given /^the "([^"]*)" user has not received an email '([^']*)'$/
   */
  public function theUserNotReceivedAnEmail($user, $title) {
    $mail_address = $this->getMailAddress($user);
    $title = $this->fixStepArgument($title);

    $mbox = imap_open( $this->email['mailbox'], $mail_address,  $this->email['password']);

    $all = imap_check($mbox);

    $received = false;
    // Trying 10 times with three seconds pause
    for ($attempts = 0; $attempts++ < 10; ) {

      if ($all->Nmsgs) {
        foreach (imap_fetch_overview($mbox, "1:$all->Nmsgs") as $msg) {
          if ($msg->to == $mail_address && strpos($msg->subject, $title) !== FALSE) {
            $msg->body = imap_fetchbody($mbox, $msg->msgno, 1);
            // Consider if we start sending HTML emails.
            //$msg->body['html'] = imap_fetchbody($mbox, $msg->msgno, 2);
            $this->mailMessages[$user][] = $msg;
            imap_delete($mbox, $msg->msgno);
            $received = true;
            break 2;
          }
        }
      }
      sleep(3);
    }
    imap_close($mbox);
    // Throw Exception if message not found.
    if ($received) {
      throw new \Exception('Email "' . $title . '" to "' . $mail_address . '" has been received.');
    }
  }

  /**
   * @When /^user "([^"]*)" clicks link containing "(?P<link>[^"]*)" in mail(?: (?:titled )?'(?P<title>[^']*)')?$/
   */
  public function userClickLinkContainingInMail($user, $link_substring, $title = NULL) {

    $link_substring = $this->fixStepArgument($link_substring);
    $title = $title ? $this->fixStepArgument($title) : NULL;

    foreach ($this->mailMessages[$user] as $msg) {
      if ($title && trim($msg->subject) == $title) {

        if (!empty($msg->body)) {

          // Look for matching link text.
          $body = str_replace('\n', '', $msg->body);

          // Get all links.
          if (preg_match_all('/https?:\/\/.*/i', $body, $matches)) {
            $links = array_shift($matches);

            foreach ($links as $link) {
              if (strpos($link, $link_substring) !== false) {
                $this->getSession()->visit($link);
              }
            }
          }
        }
      }
    }
//    throw new \Exception('Email "' . $title . '" does not have a link with the text "' . $link_substring . '".');
//    throw new \Exception('Email "' . $title . '" not received.');
//    throw new \Exception('Email "' . $title . '" does not have any links.');
  }

  /**
   * @When /^user "([^"]*)" clicks link matching "(?P<link_regex>[^"]*)" in mail(?: (?:titled )?'(?P<title>[^']*)')?$/
   */
  public function userClickLinkMatchingInMail($user, $link_regex, $title = NULL) {

    $title = $title ? $this->fixStepArgument($title) : NULL;

    foreach ($this->mailMessages[$user] as $msg) {
      if ($title && trim($msg->subject) == $title) {

        if (!empty($msg->body)) {

          // Look for matching link text.
          $body = str_replace('\n', '', $msg->body);

          // Get all links.
          if (preg_match_all('/https?:\/\/.*/i', $body, $matches)) {
            $links = array_shift($matches);

            foreach ($links as $link) {
              preg_match('/' . $link_regex . '/i', trim($link), $hef_matches);
              if (!empty($hef_matches)) {
                $this->getSession()->visit($link);
              }
            }
          }
        }
      }
    }
//    throw new \Exception('Email "' . $title . '" does not have a link with the text "' . $link_substring . '".');
//    throw new \Exception('Email "' . $title . '" not received.');
//    throw new \Exception('Email "' . $title . '" does not have any links.');
  }

  /**
   * @Given /^that the user "([^"]*)" is not registered$/
   */
  public function thatTheUserIsNotRegistered($user_name) {
    try {
      $this->getDriver()->drush('user-cancel', array($user_name), array('yes' => NULL, 'delete-content' => NULL));
    }
    catch (Exception $e) {
      if(strpos($e->getMessage(), 'Unable to find') < 1){
        // Print exception message if exception is different than expected
        print $e->getMessage();
      }
    }
  }

  /**
   * @Given /^"([^"]*)" option in "([^"]*)" should be disabled$/
   */
  public function optionInShouldBeDisabled($option_key, $label) {

    $page = $this->getSession()->getPage();

    $select = $page->find('xpath', "//label[contains(., '$label')]/following-sibling::select");

    if ($select) {
      $dom = new domDocument;
      $dom->loadHTML($select->getHtml());
      $options = $dom->getElementsByTagName('option');
      foreach ($options as $option) {
        if($option->getAttribute('disabled') && $option->nodeValue == $option_key) {
          return;
        }
      }

    }

    throw new ElementNotFoundException(
      $this->getSession(), 'select option', 'value|text', $option_key
    );

  }

  /**
   * @Given /^"([^"]*)" option in "([^"]*)" should be selected$/
   */
  public function optionInShouldBeSelected($option_key, $label) {

    $page = $this->getSession()->getPage();

    $select = $page->find('xpath', "//label[contains(., '$label')]/following-sibling::select");

    if ($select) {
      $dom = new domDocument;
      $dom->loadHTML($select->getHtml());
      $options = $dom->getElementsByTagName('option');
      foreach ($options as $option) {
        if($option->getAttribute('selected') && $option->nodeValue == $option_key) {
          return;
        }
      }

    }

    throw new ElementNotFoundException(
      $this->getSession(), 'select option', 'value|text', $option_key
    );

  }

  /**
   * @Then /^I (?:|should )see page title "(?P<title>[^"]*)"$/
   */
  public function assertPageTitle($title) {
    $results = $this->getSession()->getPage()->findAll('css', 'h1.page-header');
    foreach ($results as $result) {
      if ($result->getText() == $title) {
        return;
      }
    }
    throw new \Exception(sprintf("The text '%s' was not found in page title on the page %s", $title, $this->getSession()->getCurrentUrl()));
  }

  /**
   * @Then /^I (?:|should )see node title "(?P<title>[^"]*)"$/
   */
  public function assertNodeTitle($title) {
    $results = $this->getSession()->getPage()->findAll('css', 'article.node h1.node-title');
    foreach ($results as $result) {
      if ($result->getText() == $title) {
        return;
      }
    }
    if (count($results)) {
      throw new \Exception(sprintf("The text '%s' was not found in node title on the page %s", $title, $this->getSession()->getCurrentUrl()));
    }
    else {
      throw new \Exception(sprintf("Node title missing on the page %s", $title, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Given /^view "([^"]*)" view should have "([^"]*)" rows$/
   */
  public function viewViewShouldHaveRows($view_display_id, $rows) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }
    $view_rows = $view->findAll('css', '.views-row');
    if (count($view_rows) != $rows) {
      throw new \Exception('View with display id "' . $view_display_id . '" has ' . count($view_rows) . ' rows instead of ' . $rows. '.');
    }
  }

  /**
   * @Given /^pager in "([^"]*)" view should match "([^"]*)"$/
   */
  public function pagerInViewShouldMatch($view_display_id, $regex) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }
    $pager = $view->find('css', '.pagination');

    if (empty($pager)) {
      throw new \Exception('View "' . $view_display_id . '" doesn\' have a pager.');
    }

    $text = $pager->getText();
    preg_match('/' . $regex . '/i', $text, $match);

    if (empty($match)) {
      throw new Exception('Pager in view "' . $view_display_id. '" contains "' . $text . '" which doesn\'t match "' . $regex . '"');
    }
  }

  /**
   * @Given /^pager should match "([^"]*)"$/
   */
  public function pagerShouldMatch($regex) {
    $pager = $this->getSession()->getPage()->find('css', '.pagination');

    if (empty($pager)) {
      throw new \Exception('Pager not found.');
    }

    $text = $pager->getText();
    preg_match('/' . $regex . '/i', $text, $match);

    if (empty($match)) {
      throw new Exception('Pager contains "' . $text . '" which doesn\'t match "' . $regex . '"');
    }
  }


  /**
   * @Then /^"([^"]*)" field in row "([^"]*)" of "([^"]*)" view should match "([^"]*)"$/
   */
  public function fieldInRowOfViewShouldMatch($field_name, $row, $view_display_id, $regex) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }

    $view_row = $view->find('css', '.views-row-' . $row);
    if (empty($view_row)) {
      throw new \Exception('Row "' . $row . '" in view "' . $view_display_id . '" not found.');
    }

    $field = $view_row->find('css', '.views-field-' . $field_name);

    if (empty($field)) {
      throw new \Exception('Field "' . $field_name. '" in row "' . $row . '" of view "' . $view_display_id . '" not found.');
    }

    $text = $field->getText();
    preg_match('/' . $regex . '/i', $text, $match);

    if (!$field->isVisible()) {
      throw new Exception('Field "' . $field_name. '" found but it\'s not visible');
    }
    elseif (empty($match)) {
      throw new Exception('Field "' . $field_name. '" found but it contains "' . $text . '" which doesn\'t match "' . $regex . '"');
    }
  }

  /**
   * @Then /^avatar in row "([^"]*)" of "([^"]*)" view should link to "([^"]*)"$/
   */
  public function avatarInRowOfViewShouldLinkTo($row, $view_display_id, $href) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }

    $view_row = $view->find('css', '.views-row-' . $row);
    if (empty($view_row)) {
      throw new \Exception('Row "' . $row . '" in view "' . $view_display_id . '" not found.');
    }

    $avatar = $view_row->find('css', '.field-avatar');
    $link = $avatar->findLink('');
    if (empty($link)) {
      throw new \Exception('Avatar in row "' . $row . '" of view "' . $view_display_id . '" is not a link.');
    }

    $href_property = $link->getAttribute('href');
    // Use regex to get relative url.
    // We expect relative urls as we set them in the HTML but selenium returns
    // Dom property instead of HTML attribute whis us absolute url in most cases
    // https://code.google.com/p/selenium/issues/detail?id=1824
    if (preg_replace('/http(s)?:\/\/[^\/]*/i', '', $href_property) != $href) {
      throw new \Exception('Avatar in row "' . $row . '" of view "' . $view_display_id . '" links to "' . $href_property . '" instead of "' . $href . '".');
    }

    $img = $link->find('css', 'img');
    if (empty($img)) {
      throw new \Exception('Avatar in row "' . $row . '" of view "' . $view_display_id . '" doesn\' contain user picture.');
    }
  }

  /**
   * @When /^I click "([^"]*)" field in row "([^"]*)" of "([^"]*)" view$/
   */
  public function clickFieldInRowOfView($field_name, $row, $view_display_id) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }

    $view_row = $view->find('css', '.views-row-' . $row);
    if (empty($view_row)) {
      throw new \Exception('Row "' . $row . '" in view "' . $view_display_id . '" not found.');
    }

    $field = $view_row->find('css', '.views-field-' . $field_name);

    if (empty($field)) {
      throw new \Exception('Field "' . $field_name. '" in row "' . $row . '" of view "' . $view_display_id . '" not found.');
    }

    $link = $field->findLink('');
    if (empty($link)) {
      throw new \Exception('Field "' . $field_name. '" in row "' . $row . '" of view "' . $view_display_id . '" is not a link.');
    }
    $link->click();
  }

  /**
   * @Then /^row "([^"]*)" of "([^"]*)" view should match "([^"]*)"$/
   */
  public function rowOfViewShouldMatch($row, $view_display_id, $regex) {
    $view = $this->getSession()->getPage()->find('css', '.view-display-id-' . $view_display_id);
    if (empty($view)) {
      throw new \Exception('View with display id "' . $view_display_id . '" not found.');
    }

    $view_row = $view->find('css', '.views-row-' . $row);
    if (empty($view_row)) {
      throw new \Exception('Row "' . $row . '" in view "' . $view_display_id . '" not found.');
    }

    $row_content = $view_row->getText();
    preg_match('/' . $regex . '/i', $row_content, $match);

    if (empty($match)) {
      throw new Exception('Row "' . $row . '" of view "' . $view_display_id . '" contains "' . $row_content . '" what doesn\'t match "' . $regex . '"');
    }
  }

  /**
   * @Then /^"([^"]*)" item in "([^"]*)" subnav should be active$/
   */
  public function itemInSubnavShouldBeActive($item, $menu) {
    $subnav = $this->getSession()->getPage()->find('css', '.subnav-' . strtolower($menu));
    if (empty($subnav)) {
      throw new \Exception('"' . $menu . '" sub navigation not found.');
    }
    elseif (!$subnav->isVisible()) {
      throw new Exception('"' . $menu . '" sub navigation is not active sub navigation');
    }

    $link = $subnav->findLink($item);
    if (empty($link)) {
      throw new \Exception('"' . $item . '" menu item not found.');
    }

    $classes = $link->getAttribute('class');
    if (strpos($classes,'active') === false) {
      throw new \Exception('"' . $item . '" menu item is not active.');
    }

  }

  /**
   * @Given /^there should be "([^"]*)" search results on the page$/
   */
  public function thereShouldBeSearchResultsOnThePage($expected_number) {
    $items = $this->getSession()->getPage()->findAll('css', '.search-results .search-result');
    if (empty($items)) {
      throw new \Exception('No search results found.');
    }
    if (count($items) != $expected_number) {
      throw new \Exception('There are ' . count($items) . ' search results instead of ' . $expected_number . '.');
    }
  }

  /**
   * @Given /^I have an image "([^"]*)" x "([^"]*)" pixels titled "([^"]*)" located in "([^"]*)" folder$/
   */

  public function iHaveAnImageTitledLocatedInFolder($width, $height, $title, $path) {
    $image = @imagecreatetruecolor($width, $height) or die('Cannot Initialize new GD image stream');
    $color = array(
      imagecolorallocate($image,rand(100, 150),rand(100, 150),rand(100, 150)),
      imagecolorallocate($image,rand(50, 100),rand(50, 100),rand(50, 100)),
    );

    for ($y = 0; $y < $height / 5; $y++) {
      $i=$y % 2;
      for ($x = 0; $x < $width / 5; $x++) {
        imagefilledrectangle($image, $x*5, $y*5, $x*5 + 5, $y*5 + 5, $color[++$i % 2]);
      }
    }

    imagestring($image, 5, $width/2 - strlen($title) * 4.5 , $height/2 - 15, $title, imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $path . '/' . $title . '.png');
    imagedestroy($image);
  }

  /**
   * @Given /^I have a txt file titled "([^"]*)" located in "([^"]*)" folder$/
   */
  public function iHaveATxtFileTitledLocatedInFolder($title, $path){
    $file = $path . $title;
    $contents = "Test txt file.";
    $handle = fopen($file, "w");
    if(!$handle){
      die("Can't open $file");
    }
    else{
      fwrite($handle, $contents);
      fclose($handle);
    }
  }

  /**
   * @Given /^user "([^"]*)" belongs to "([^"]*)" publisher$/
   */
  public function userBelongsToPublisher($user_name, $publisher_name) {
    try {
      $drush = $this->getDriver();
      $publisher_id = $drush->drush('ev', array('"\$query = new EntityFieldQuery(); \$result = \$query->entityCondition(\'entity_type\', \'ckan_publisher\')->propertyCondition(\'title\', \'Academics\')->execute(); \$publisher = reset(\$result[\'ckan_publisher\']); print \$publisher->id;"'));
      $uid = $drush->drush('ev', array('"\$user = user_load_by_name(\'' . $user_name . '\'); \$user->field_publishers[\'und\'][0][\'target_id\'] = ' . $publisher_id . '; user_save(\$user);"'));
    }
    catch (Exception $e) {
      throw new \Exception('PHP evaluation failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^I set digest last run to (\d+) day(?:s|) ago$/
   */
  public function iSetDigestLastRunToDayAgo($days) {
    try {
      $timestamp = time() - 60 * 60 * 24 * $days;
      $drush = $this->getDriver();
      $drush->drush('vset', array('"message_digest_1 day_last_run"', $timestamp));
      $drush->drush('vset', array('"message_digest_1 week_last_run"', $timestamp));
    }
    catch (Exception $e) {
      throw new \Exception('Setting digest last run via Drush vset command failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^user "([^"]*)" created "([^"]*)" titled "([^"]*)"$/
   */
  public function userCreatedTitled($user_name, $node_type, $title) {
    try {
      $drush = $this->getDriver();
      $uid = $drush->drush('ev', array('"\$user = user_load_by_name(\'' . $user_name . '\'); print \$user->uid;"'));
      $drush->drush('ev', array('"\$values = array(\'type\' => \'' . $node_type . '\', \'uid\' => \'' . $uid . '\', \'status\' => \'1\', \'comment\' => \'1\',); \$entity = entity_create(\'node\', \$values); \$wrapper = entity_metadata_wrapper(\'node\', \$entity); \$wrapper->title->set(\'' . $title . '\'); \$wrapper->body->set(array(\'value\' => \'Lorem ipsum\')); \$wrapper->save();"'));
    }
    catch (Exception $e) {
      throw new \Exception('PHP evaluation failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^(?:|that )the dataset with name "([^"]*)" doesn\'t exist in Drupal$/
   */
  public function thatTheDatasetDoesnTExistInDrupal($dataset_name) {
    try {
      $drush = $this->getDriver();
      $dataset_id = $drush->drush('ev', array('"\$query = new EntityFieldQuery(); \$result = \$query->entityCondition(\'entity_type\', \'ckan_dataset\')->propertyCondition(\'name\', \'' . $dataset_name . '\')->execute(); print empty(\$result[\'ckan_dataset\']) ? \'false\' : reset(\$result[\'ckan_dataset\'])->id;"'));
      if (is_numeric($dataset_id)) {
        $drush->drush('ev', array('"entity_delete(\'ckan_dataset\', ' . $dataset_id . ');"'));
      }
    }
    catch (Exception $e) {
      throw new \Exception('PHP evaluation failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^that dataset with titled "([^"]*)" with name "([^"]*)" published by "([^"]*)" exists and has no resources$/
   */
  public function thatDatasetWithTitledWithNamePublishedByExistsAndHasNoResources($title, $name, $publisher, $package_json = NULL) {

    try {
      $client = $this->ckan_get_client();

      $response = $client->PackageSearch(array('fq' => "name:$name"));
      $result = $response->toArray();

      if (empty($package_json)) {
        $package_json = $this->get_json_package($title, $name, $publisher);
      }

      if (empty($result['result']['results'])) {
        $response = $client->PackageCreate(array('data'=>$package_json));
        $result = $response->toArray();
        if (!$result['success']) {
          throw new \Exception("Failed to create '$title' dataset.");
        }

      } else {
        $response = $client->PackageUpdate(array('data'=>$package_json));
        $result = $response->toArray();
        if (!$result['success']) {
          throw new \Exception("Failed to purge resources on '$title' dataset.");
        }
      }
    }
    catch (Exception $e) {
      throw new \Exception('CKAN client failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^I attach "([^"]*)" resource to "([^"]*)" dataset$/
   */
  public function iAttachResourceToDataset($resource_url, $dataset_name) {
    try {
      $client = $this->ckan_get_client();

      $dataset = $client->GetDataset(array('id' => $dataset_name))->toArray();

      if (!empty($dataset['result'])) {

        $title = $dataset['result']['title'];
        $publisher = $dataset['result']['organization']['name'];
        $url_parts = explode('/', $resource_url);
        $resource_description = end($url_parts);

        $resources = empty($dataset['result']['resources']) ? array() : $dataset['result']['resources'];
        $resources[] = array('url' => $resource_url, 'description' => $resource_description, 'format' => 'TEST');

        $package_json = $this->get_json_package($title, $dataset_name, $publisher, $resources);

        $response = $client->PackageUpdate(array('data'=>$package_json));
        $result = $response->toArray();
        if (!$result['success']) {
          throw new \Exception("Failed to purge resources on '$title' dataset.");
        }

      } else {
        throw new \Exception("Dataset '$dataset_name' not found.");
      }

    }
    catch (Exception $e) {
      throw new \Exception('CKAN client failed. ' . $e->getMessage());
    }
  }

  private function get_json_package($title, $name, $publisher, array $resources = array()) {
    $package = array(
      'name' => $name,
      'title' => $title,
      'owner_org' => $publisher,
      'license_id' => 'uk-ogl',
      'notes' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
      'resources' => $resources,
    );

    return json_encode($package);
  }

  /**
   * @When /^I synchronise dataset with name "([^"]*)"$/
   */
  public function iSynchroniseDatasetWithName($dataset_name) {

    try {
      $client = $this->ckan_get_client();

      $response = $client->PackageSearch(array('fq' => "name: $dataset_name"));
      $result = $response->toArray();
    }
    catch (Exception $e) {
      throw new \Exception('CKAN client failed. ' . $e->getMessage());
    }

    try {
      if (!empty($result['result']['results']['0']['id'])) {
        $drush = $this->getDriver();
        $drush->drush('ckan_resync_dataset', array($result['result']['results']['0']['id']));
      }
      else {
        throw new \Exception("Dataset '$dataset_name' doesn't exist in CKAN.");
      }
    }
    catch (Exception $e) {
      throw new \Exception('PHP evaluation failed. ' . $e->getMessage());
    }
  }

  /**
   * @When /^I open comment form for dataset with name "([^"]*)"$/
   */
  public function iOpenCommentFormForDatasetWithName($dataset_name) {
    try {
      $client = $this->ckan_get_client();

      $dataset = $client->GetDataset(array('id' => $dataset_name))->toArray();

      if (!empty($dataset['result'])) {

        $ckan_id = $dataset['result']['id'];
        $this->getSession()->visit($this->locatePath('/comment/dataset/' . $ckan_id));

      } else {
        throw new \Exception("Dataset '$dataset_name' not found.");
      }

    }
    catch (Exception $e) {
      throw new \Exception('CKAN client failed. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^I get comments of dataset named "([^"]*)"$/
   */
  public function iGetCommentsOfDatasetNamed($dataset_name) {
    try {
      $client = $this->ckan_get_client();

      $dataset = $client->GetDataset(array('id' => $dataset_name))->toArray();

      if (!empty($dataset['result'])) {

        $ckan_id = $dataset['result']['id'];
        $this->getSession()->visit($this->locatePath('/comment/get/' . $ckan_id));

      } else {
        throw new \Exception("Dataset '$dataset_name' not found.");
      }

    }
    catch (Exception $e) {
      throw new \Exception('CKAN client failed. ' . $e->getMessage());
    }
  }

  /**
   * Get a ckan client instance.
   */
  private function ckan_get_client() {
    try {
      $drush = $this->getDriver();
      $base_url = json_decode($drush->drush('vget', array('ckan_url', '--format=json')));
      $api_key = json_decode($drush->drush('vget', array('ckan_apikey', '--format=json')));

      return Silex\ckan\CkanClient::factory(array(
        'baseUrl' => $base_url->ckan_url,
        'apiKey' => $api_key->ckan_apikey,
      ));
    }
    catch (Exception $e) {
      throw new \Exception('Unable to instantiate CKAN client. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^TEST$/
   */
  public function test() {
    try {
      $drush = $this->getDriver();
      $result = $drush->drush('sqlq', array('"SELECT nid FROM node n WHERE n.type = \'dataset_request\' ORDER BY nid DESC;"'));
    }
    catch (Exception $e) {
      throw new \Exception('Query failed ' . $e->getMessage());
    }

    $nids = explode("\n", $result);

    // remove first element which is column name 'nid'
    array_shift($nids);
    array_pop($nids);

    $session = $this->getSession();

    $no_message = array();
    $dif_message = array();


    foreach ($nids as $nid) {

      print $nid . " | ";

      $session->visit($this->locatePath('/node/' . $nid . '/edit'));
      sleep(1);
      $page = $this->getSession()->getPage();
      sleep(1);
      $last_vertical_tab = $page->find('css', '.vertical-tabs-list .last a');
      if (is_object($last_vertical_tab)) {
        $last_vertical_tab->click();
        sleep(1);
        $last_vertical_tab = $page->find('css', '.vertical-tabs-list .last a');

        $moderation_state = $page->find('css', '.form-item-workbench-moderation-state-new');
        $moderation_state->selectFieldOption('Moderation state', 'Published');
        $page->pressButton('Save');
        sleep(1);

        $message = 'has been updated.';
        $successSelector = $this->getDrupalSelector('success_message_selector');
        $successSelectorObj = $this->getSession()->getPage()->find("css", $successSelector);
        if(empty($successSelectorObj)) {
          $no_message[] = $nid;
        }
        elseif (strpos(trim($successSelectorObj->getText()), $message) === FALSE) {
          $dif_message[] = $nid;
        }
      }
      else {
        $no_message[] = $nid;
      }
    }

    if (!empty($no_message)) {
      print "\nNo success message on:\n";
      print implode(' | ', $no_message);

    }
    if (!empty($dif_message)) {
      print "\nMessage different on\n";
      print implode(' | ', $dif_message);
    }


  }

  /**
   * @Given /^there is a test page with "([^"]*)" path$/
   */
  public function thereIsATestPageWithPath($path) {
    return array (
      new Given("I visit \"" . $path . "\""),
      new Given("I should see \"The requested page could not be found.\""),
      new Given("that the user \"test_admin\" is not registered"),
      new Given("I am logged in as a user \"test_admin\" with the \"administrator\" role"),
      new Given("I visit \"/node/add/page\""),
      new Given("I fill in \"Title\" with \"Test page\""),
      new Given("I press \"Save\""),
      new Given("I should see \"Page Test page has been created.\""),
    );
  }

  /**
   * @Given /^I submit "([^"]*)" titled "([^"]*)" for moderation$/
   */
  public function iSubmitTitledForModeration($content_type, $title) {
    return array (
      new Given("I follow \"My Drafts\""),
      new Given("I wait until the page loads"),
      new Given("I follow \"$title\""),
      new Given("I wait until the page loads"),
      new Given("I follow \"Edit draft\""),
      new Given("I wait until the page loads"),
      new Given("I press \"Submit for moderation\""),
      new Given("I wait until the page loads"),
      new Given("I should see a message about \"$content_type\" being submitted for moderation"),
      new Given("I follow \"Manage my content\""),
      new Given("I wait until the page loads"),
      new Given("I should see the link \"$title\""),
      new Given("I follow \"My Drafts\""),
      new Given("I wait until the page loads"),
      new Given("I should see the link \"$title\""),
      new Given("I should see \"Needs review\""),
      new Given("I should see the link \"Draft\""),
    );
  }

  /**
   * @Given /^user with "([^"]*)" role moderates "([^"]*)" authored by "([^"]*)"$/
   */
  public function userWithRoleModeratesAuthoredBy($role, $title, $author) {
    return array (
      new Given("that the user \"test_moderator\" is not registered"),
      new Given("I am logged in as a user \"test_moderator\" with the \"$role\" role"),
      new Given("I visit \"/admin/workbench\""),
      new Given("I follow \"Needs review\""),
      new Given("I wait until the page loads"),
      new Given("I follow \"$title\""),
      new Given("I wait 2 seconds"),
      new Given("I follow \"Moderate\""),
      new Given("I should see \"Currently there is no published revision of this node.\""),
      new Given("I should see \"Revised by $author\""),
      new Given("I should see the link \"$author\" in the \"main_content\" region"),
      new Given("I should not see the link \"test_moderator\" in the \"main_content\" region"),
      new Given("\"Published\" option in \"Moderation state\" should be selected"),
      new Given("I press \"Apply\""),
      new Given("I wait until the page loads"),
      new Given("I should see \"This is the published revision.\""),
      new Given("I should see the link \"test_moderator\" in the \"main_content\" region"),
    );
    // Add this when http://redmine.co-dev1.dh.bytemark.co.uk/issues/1372 is fixed and moderation block brought back
    //new Given("I follow \"View\""),
    //new Given("I should see \"Revision state: Published\""),
    //new Given("I should see \"Current draft: Yes\""),
    //new Given("I should see the link \"Unpublish this revision\" in the \"main_content\" region"),

  }

  /**
   * @Then /^I should see "([^"]*)" in My content and All content tabs but not in My drafts tab$/
   */
  public function iShouldSeeInMyAndAllContentTabsButNotInMyDraftsTab($title) {
    return array (
      new Given("I visit \"/admin/workbench\""),
      new Given("I should see the link \"$title\""),
      new Given("I follow \"My Drafts\""),
      new Given("I wait until the page loads"),
      new Given("I should not see the link \"$title\""),
      new Given("I follow \"My Content\""),
      new Given("I wait until the page loads"),
      new Given("I follow \"$title\""),
      new Given("I wait until the page loads"),
      new Given("I should see the link \"New draft\""),
      new Given("I should see the link \"Add new comment\""),
      new Given("I should see \"View published\""),
    );
  }



  //drush sqlq 'truncate field_data_field_organogram; truncate field_revision_field_organogram; truncate dgu_organogram; delete from file_managed where filemime="application/vnd.ms-excel";'; rm -f /var/www/drupal/dgud7/current/sites/default/files/organogram/uploads/*

  /**
   * @Given /^I upload organograms$/
   */
  public function iUploadOrganograms() {

    $drush = $this->getDriver();

    ini_set('auto_detect_line_endings',TRUE);
    $handle = fopen('/home/pawel/data/tso_combined.csv','r');
    $ignore_this = fgetcsv($handle);
    $messages = array();
    $i = 0;

    $failed = array(822,823,824,825,826,827,828,829,830); // array(338, 426, 716, 750, 981, 1000, 1058, 1062, 1066, 1101);

    while (($row = fgetcsv($handle)) !== FALSE ) {
     if (in_array(++$i, $failed)) { // if ($i++ >= 971) {

        $title = str_replace("'", "\'", $row[0]);
        $version_array = explode('-', $row[1]);
        $version = implode('-', array_reverse($version_array));
        $xls_path = $row[2];

        //published, signed off, uploaded
        $status = $row[7];

        if (file_exists('/media/pawel/Data/organogram-data/' . $xls_path)) {


          // Trying to get publisher id 10 times.
          for ($j = 0; $j <10; $j++) {
            try {
              $name = $drush->drush('ev', array('"\$name = db_select(\'ckan_publisher\', \'p\')->fields(\'p\', array(\'name\'))->condition(\'p.title\', \'' . $title . '\' )->execute()->fetchField(); print \$name;"'));
              break;
            }
            catch (Exception $e) {}
          }

          if (!$name) {
            print 'Can\'t get publisher id for "' . $title;
            continue;
          }

          $session = $this->getSession();
          $session->visit($this->locatePath('/organogram/manage/' . $name));
          sleep(3);
          $page = $session->getPage();

          // Find current row in the table
          $date = date('d M Y', strtotime($version));
          $date_cell = $page->find('named', array('content', $date));

          if(empty($date_cell)) {
            sleep(25);
            $page = $session->getPage();
            $date_cell = $page->find('named', array('content', $date));
            if(empty($date_cell)) {
              print "\n" . str_pad($i, 5) . str_pad('http://test.data.gov.uk/organogram/manage/' . $name, 100, ' ') . "[$version] " . 'Date ' . $date . 'not found';
              continue;
            }
          }

          $current_row = $date_cell->getParent();

          $xls_link = $current_row->find('css', 'td.file span.file a');
          if(!empty($xls_link)) {
            print "\n" . str_pad($i, 5) . str_pad('http://test.data.gov.uk/organogram/manage/' . $name, 100, ' ') . "[$version] " . 'XLS file already uploaded';
            continue;
          }

          $upload_button = $current_row->findButton('Upload');
          if(!$upload_button) {
            print "Upload button for version $version not found.";
            continue;
          }
          $upload_button->press();
          sleep(2);

          $input = $page->find('css', '#ckan-publisher-form input.form-file');
          if(empty($input)) {
            sleep(20);
            $page = $session->getPage();
            $input = $page->find('css', '#ckan-publisher-form input.form-file');
            if(empty($input)) {
              print "Organogram upload input box not found";
              continue;
            }
          }

          $input->attachFile('/media/pawel/Data/organogram-data/' . $xls_path);
          sleep(2);
          $upload_submit_button = $page->find('css','#ckan-publisher-form input.form-submit');
          if(empty($upload_submit_button)) {
            print "Organogram upload button not found";
            continue;
          }
          $upload_submit_button->press();
          print "\n" . str_pad($i, 5) . str_pad('http://test.data.gov.uk/organogram/manage/' . $name, 100, ' ') . "[$version] ";

          sleep(10);

          //->find('css', '#edit-field-organogram-und-table a:contains("Organogram date ' . $version . '")');
          $xls_link = $current_row->find('css', 'td.file span.file a');

          if(empty($xls_link)) {
            sleep(35);
            $xls_link = $current_row->find('css', 'td.file span.file a');

            if(empty($xls_link)) {

              $error_message = $page->find('css', '.alert-danger');
              if(!empty($error_message)) {
                $message_text = substr($error_message->getText(), 24);
                print "\n\n" . $message_text . "\n-------------------------------------------------------------------------\n";
              }


              print 'XLS file upload failed';
              continue;
            }
          }

          if ($status == 'uploaded') {
            print " Uploaded";
            continue;
          }

          $preview_link = $current_row->find('css', '.organogram-preview');
          if(empty($preview_link)) {
            print "Preview link for version $version not found.";
            continue;
          }
          $preview_link->click();
          sleep(8);
          $vis_node = $page->find('css', '.organogram-preview .node');
          $ok = ' PUBLISHED';
          if(empty($vis_node)) {
            sleep(20);
            $vis_node = $page->find('css', '.organogram-preview .node');
            if(empty($vis_node)) {
              print 'Missing top post ';
              $ok = '';
            }
          }

          $signoff_checkbox = $current_row->find('css', '.organogram-sign-off');
          if(empty($signoff_checkbox)) {
            print "Signoff checkbox for version $version not found.";
            continue;
          }
          $signoff_checkbox->click();
          sleep(15);

          if ($status == 'signed off') {
            print " Signed off";
            continue;
          }

          $publish_button = $current_row->findButton('Publish');
          if(empty($publish_button)) {
            sleep(25);
            $publish_button = $current_row->findButton('Publish');
            if(empty($publish_button)) {
              print "Publish button for version $version not found.";
              continue;
            }
          }

          $publish_button->press();
          sleep(12);
          $success_message = $page->find('css', '.drupal-messages #messages .alert-success');
          if(!empty($success_message)) {
            $message_text = $success_message->getText();
            if (strpos($message_text, 'Data published successfully') === FALSE) {
              print " | Error publishing CSVs for version $version. ";
            }
          }

          $error_message = $page->find('css', '.form-type-managed-file .alert-danger');
          if(!empty($error_message)) {
            $message_text = substr($error_message->getText(), 24);
            if (!in_array($message_text, $messages)) {
              $messages[] = $message_text;
            }
            print ' | ' . $message_text;
            $ok = '';
          }
          print $ok;
        }
        else {
          print "\nfile not found: " . $xls_path . ' (version ' . $version . ') [' . $i . ']';
        }
      }

    }
    ini_set('auto_detect_line_endings',FALSE);
    print "\n\n-----------------------------------------------\nERROR MESSAGES:";
    foreach ($messages as $message) {
      print "\n" . $message;
    }

    //$scv = getcsv(file_get_contents('https://raw.githubusercontent.com/datagovuk/organograms/gh-pages/uploads_report_tidied.csv'),);
  }

  /**
   * @Given /^I validate organograms$/
   */
  public function iValidateOrganograms() {

    $drush = $this->getDriver();

    ini_set('auto_detect_line_endings',TRUE);
    $handle = fopen('/home/pawel/organogram_upload.log','r');
    $i = 0;
    $messages = array();

    while (($row = fgets($handle)) !== FALSE ) {
      if ($i++ >= 0) {
        $matches = [];
        preg_match_all('/http:\/\/[\w|.]*\/\w*\/(\d*)\/\w* \(version ([0-9-]*)/i', $row, $matches);

        $publisher_id = $matches[1][0];
        $version = $matches[2][0];

        $id_str = (string) $publisher_id;
        $extra_space = 4 - strlen($id_str);

        $session = $this->getSession();
        $session->visit($this->locatePath('/ckan_publisher/' . $publisher_id . '/edit'));
        sleep(2);
        $page = $session->getPage();


        print "\n" . str_pad($i, 5) . str_pad('http://test.data.gov.uk/ckan_publisher/' . $publisher_id . '/edit', 50, ' ') . "[$version] ";

        $xls_link = $page->find('css', '#edit-field-organogram-und-table a:contains("Organogram date ' . $version . '")');
        if(empty($xls_link)) {
          print 'Missing XLS file';
          continue;
        }

        $table_row = $xls_link->getParent()->getParent()->getParent()->getParent();
        $preview_button = $table_row->findButton('Preview');
        if(empty($preview_button)) {
          throw new Exception("Preview button for version $version not found.");
        }
        $preview_button->press();
        sleep(5);
        $vis_node = $page->find('css', '.organogram-preview .node');
        $ok = 'OK';
        if(empty($vis_node)) {
          print 'Missing top post ';
          $ok = '';
        }

        $error_message = $page->find('css', '.form-type-managed-file .alert-danger');
        if(!empty($error_message)) {
          $message_text = substr($error_message->getText(), 24);
          if (!in_array($message_text, $messages)) {
            $messages[] = $message_text;
          }
          print ' | ' . $message_text;
          $ok = '';
        }
        print $ok;
      }

    }
    ini_set('auto_detect_line_endings',FALSE);
    print "\n\n-----------------------------------------------\nERROR MESSAGES:";
    foreach ($messages as $message) {
      print "\n" . $message;
    }
  }


  /**
   * @Given /^I cleanup organograms$/
   */
  public function iCleanupOrganograms() {

    $drush = $this->getDriver();

    ini_set('auto_detect_line_endings',TRUE);
    $handle = fopen('/home/pawel/data/tso_combined.csv','r');
    $ignore_this = fgetcsv($handle);
    $publishers = array();
    while (($row = fgetcsv($handle)) !== FALSE ) {
      $title = str_replace("'", "\'", $row[0]);
      if (!in_array($title, $publishers)) { // && $title == 'Cabinet Office'
        // Trying to get publisher id 10 times.
        for ($j = 0; $j <10; $j++) {
          try {
            $name = $drush->drush('ev', array('"\$name = db_select(\'ckan_publisher\', \'p\')->fields(\'p\', array(\'name\'))->condition(\'p.title\', \'' . $title . '\' )->execute()->fetchField(); print \$name;"'));
            break;
          }
          catch (Exception $e) {}
        }

        if (!$name) {
          print "\n" . 'Can\'t get publisher id for "' . $title;
          continue;
        }
        $publishers[$name] = $title;
        print "\nAdded: " . $name;
      }
    }

    $package = array(
      //'name' => 'organogram-' . $group['name'],
      'title' => 'Organogram of Staff Roles & Salaries',
      //'owner_org' => $group['name'],
      'license_id' => 'uk-ogl',
      'notes' => "Organogram (organisation chart) showing all staff roles. Names and salaries are also listed for the Senior Civil Servants.\r\n\r\nOrganogram data is released by all central government departments and their agencies since 2010. Snapshots for 31st March and 30th September are published by 6th June and 6th December each year. The published data is validated and released in CSV format and OGL-licensed for reuse. For more information about this series, see: http://guidance.data.gov.uk/organogram-data.html",
      'tags' =>
        array(
          0 => array(
            'name' => 'organograms',
          ),
        ),
      'schema' => array('d3c0b23f-6979-45e4-88ed-d2ab59b005d0'),
      'extras' =>
        array(
          0 => array(
            'key' => 'geographic_coverage',
            'value' => '111100: United Kingdom (England, Scotland, Wales, Northern Ireland)',
          ),
          1 => array(
            'key' => 'mandate',
            'value' => 'https://www.gov.uk/government/news/letter-to-government-departments-on-opening-up-data',
          ),
          2 => array(
            'key' => 'update_frequency',
            'value' => 'biannually',
          ),
          3 => array(
            'key' => 'temporal_coverage-from',
            'value' => '2010',
          ),
          4 => array(
            'key' => 'theme-primary',
            'value' => 'Government Spending',
          ),
          5 => array(
            'key' => 'import_source',
            'value' => 'organograms_v2',
          ),
        ),
    );

    print "\n\nCleaning datasets.\n";

    foreach ($publishers as $name => $title) {
      $package['name'] = 'organogram-' . $name;
      $package['owner_org'] = $name;
      $package_json = json_encode($package);
      $this->thatDatasetWithTitledWithNamePublishedByExistsAndHasNoResources($package['title'], $package['name'], $name, $package_json);
      print "\nCleaned: " . $name;
    }

    ini_set('auto_detect_line_endings',FALSE);

  }

}
