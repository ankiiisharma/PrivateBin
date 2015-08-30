<?php
/**
 * ZeroBin
 *
 * a zero-knowledge paste bin
 *
 * @link      http://sebsauvage.net/wiki/doku.php?id=php:zerobin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   http://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   0.19
 */

/**
 * zerobin
 *
 * Controller, puts it all together.
 */
class zerobin
{
    /**
     * version
     *
     * @const string
     */
    const VERSION = 'Alpha 0.19';

    /**
     * configuration array
     *
     * @access private
     * @var    array
     */
    private $_conf = array(
        'model' => 'zerobin_data',
    );

    /**
     * data
     *
     * @access private
     * @var    string
     */
    private $_data = '';

    /**
     * error message
     *
     * @access private
     * @var    string
     */
    private $_error = '';

    /**
     * status message
     *
     * @access private
     * @var    string
     */
    private $_status = '';

    /**
     * data storage model
     *
     * @access private
     * @var    zerobin_abstract
     */
    private $_model;

    /**
     * constructor
     *
     * initializes and runs ZeroBin
     *
     * @access public
     * @return void
     */
    public function __construct()
    {
        if (version_compare(PHP_VERSION, '5.2.6') < 0)
            throw new Exception('ZeroBin requires php 5.2.6 or above to work. Sorry.', 1);

        // in case stupid admin has left magic_quotes enabled in php.ini
        if (get_magic_quotes_gpc())
        {
            $_POST   = array_map('filter::stripslashes_deep', $_POST);
            $_GET    = array_map('filter::stripslashes_deep', $_GET);
            $_COOKIE = array_map('filter::stripslashes_deep', $_COOKIE);
        }

        // load config from ini file
        $this->_init();

        // create new paste or comment
        if (!empty($_POST['data']))
        {
            echo $this->_create($_POST['data']);
            return;
        }
        // delete an existing paste
        elseif (!empty($_GET['deletetoken']) && !empty($_GET['pasteid']))
        {
            $this->_delete($_GET['pasteid'], $_GET['deletetoken']);
        }
        // display an existing paste
        elseif (!empty($_SERVER['QUERY_STRING']))
        {
            $this->_read($_SERVER['QUERY_STRING']);
        }

        // display ZeroBin frontend
        $this->_view();
    }

    /**
     * initialize zerobin
     *
     * @access private
     * @return void
     */
    private function _init()
    {
        foreach (array('cfg', 'lib') as $dir)
        {
            if (!is_file(PATH . $dir . DIRECTORY_SEPARATOR . '.htaccess')) file_put_contents(
                PATH . $dir . DIRECTORY_SEPARATOR . '.htaccess',
                'Allow from none' . PHP_EOL .
                'Deny from all'. PHP_EOL,
                LOCK_EX
            );
        }

        $this->_conf = parse_ini_file(PATH . 'cfg' . DIRECTORY_SEPARATOR . 'conf.ini', true);
        foreach (array('main', 'model') as $section) {
            if (!array_key_exists($section, $this->_conf)) {
                throw new Exception("ZeroBin requires configuration section [$section] to be present in configuration file.", 2);
            }
        }
        $this->_model = $this->_conf['model']['class'];
    }

    /**
     * get the model, create one if needed
     *
     * @access private
     * @return zerobin_abstract
     */
    private function _model()
    {
        // if needed, initialize the model
        if(is_string($this->_model)) {
            $this->_model = forward_static_call(
                array($this->_model, 'getInstance'),
                $this->_conf['model_options']
            );
        }
        return $this->_model;
    }

    /**
     * Store new paste or comment
     *
     * POST contains:
     * data (mandatory) = json encoded SJCL encrypted text (containing keys: iv,salt,ct)
     *
     * All optional data will go to meta information:
     * expire (optional) = expiration delay (never,5min,10min,1hour,1day,1week,1month,1year,burn) (default:never)
     * opendiscusssion (optional) = is the discussion allowed on this paste ? (0/1) (default:0)
     * nickname (optional) = in discussion, encoded SJCL encrypted text nickname of author of comment (containing keys: iv,salt,ct)
     * parentid (optional) = in discussion, which comment this comment replies to.
     * pasteid (optional) = in discussion, which paste this comment belongs to.
     *
     * @access private
     * @param  string $data
     * @return string
     */
    private function _create($data)
    {
        header('Content-type: application/json');
        $error = false;

        // Make sure last paste from the IP address was more than X seconds ago.
        trafficlimiter::setLimit($this->_conf['traffic']['limit']);
        trafficlimiter::setPath($this->_conf['traffic']['dir']);
        if (
            !trafficlimiter::canPass($_SERVER['REMOTE_ADDR'])
        ) return $this->_return_message(
            1,
            'Please wait ' .
            $this->_conf['traffic']['limit'] .
            ' seconds between each post.'
        );

        // Make sure content is not too big.
        $sizelimit = (int) $this->_getMainConfig('sizelimit', 2097152);
        if (
            strlen($data) > $sizelimit
        ) return $this->_return_message(
            1,
            'Paste is limited to ' .
            filter::size_humanreadable($sizelimit) .
            ' of encrypted data.'
        );

        // Make sure format is correct.
        if (!sjcl::isValid($data)) return $this->_return_message(1, 'Invalid data.');

        // Read additional meta-information.
        $meta=array();

        // Read expiration date
        if (!empty($_POST['expire']))
        {
            $selected_expire = (string) $_POST['expire'];
            if (array_key_exists($selected_expire, $this->_conf['expire_options'])) {
                $expire = $this->_conf['expire_options'][$selected_expire];
            } else {
                $expire = $this->_conf['expire_options'][$this->_conf['expire']['default']];
            }
            if ($expire > 0) $meta['expire_date'] = time() + $expire;
        }

        // Destroy the paste when it is read.
        if (!empty($_POST['burnafterreading']))
        {
            $burnafterreading = $_POST['burnafterreading'];
            if ($burnafterreading !== '0')
            {
                if ($burnafterreading !== '1') $error = true;
                $meta['burnafterreading'] = true;
            }
        }

        // Read open discussion flag.
        if ($this->_conf['main']['opendiscussion'] && !empty($_POST['opendiscussion']))
        {
            $opendiscussion = $_POST['opendiscussion'];
            if ($opendiscussion !== '0')
            {
                if ($opendiscussion !== '1') $error = true;
                $meta['opendiscussion'] = true;
            }
        }

        // You can't have an open discussion on a "Burn after reading" paste:
        if (isset($meta['burnafterreading'])) unset($meta['opendiscussion']);

        // Optional nickname for comments
        if (!empty($_POST['nickname']))
        {
            // Generation of the anonymous avatar (Vizhash):
            // If a nickname is provided, we generate a Vizhash.
            // (We assume that if the user did not enter a nickname, he/she wants
            // to be anonymous and we will not generate the vizhash.)
            $nick = $_POST['nickname'];
            if (!sjcl::isValid($nick))
            {
                $error = true;
            }
            else
            {
                $meta['nickname'] = $nick;
                $vz = new vizhash16x16();
                $pngdata = $vz->generate($_SERVER['REMOTE_ADDR']);
                if ($pngdata != '')
                {
                    $meta['vizhash'] = 'data:image/png;base64,' . base64_encode($pngdata);
                }
                // Once the avatar is generated, we do not keep the IP address, nor its hash.
            }
        }

        if ($error) return $this->_return_message(1, 'Invalid data.');

        // Add post date to meta.
        $meta['postdate'] = time();

        // We just want a small hash to avoid collisions:
        // Half-MD5 (64 bits) will do the trick
        $dataid = substr(hash('md5', $data), 0, 16);

        $storage = array('data' => $data);

        // Add meta-information only if necessary.
        if (count($meta)) $storage['meta'] = $meta;

        // The user posts a comment.
        if (
            !empty($_POST['parentid']) &&
            !empty($_POST['pasteid'])
        )
        {
            $pasteid  = (string) $_POST['pasteid'];
            $parentid = (string) $_POST['parentid'];
            if (
                !filter::is_valid_paste_id($pasteid) ||
                !filter::is_valid_paste_id($parentid)
            ) return $this->_return_message(1, 'Invalid data.');

            // Comments do not expire (it's the paste that expires)
            unset($storage['expire_date']);
            unset($storage['opendiscussion']);

            // Make sure paste exists.
            if (
                !$this->_model()->exists($pasteid)
            ) return $this->_return_message(1, 'Invalid data.');

            // Make sure the discussion is opened in this paste.
            $paste = $this->_model()->read($pasteid);
            if (
                !$paste->meta->opendiscussion
            ) return $this->_return_message(1, 'Invalid data.');

            // Check for improbable collision.
            if (
                $this->_model()->existsComment($pasteid, $parentid, $dataid)
            ) return $this->_return_message(1, 'You are unlucky. Try again.');

            // New comment
            if (
                $this->_model()->createComment($pasteid, $parentid, $dataid, $storage) === false
            ) return $this->_return_message(1, 'Error saving comment. Sorry.');

            // 0 = no error
            return $this->_return_message(0, $dataid);
        }
        // The user posts a standard paste.
        else
        {
            // Check for improbable collision.
            if (
                $this->_model()->exists($dataid)
            ) return $this->_return_message(1, 'You are unlucky. Try again.');

            // New paste
            if (
                $this->_model()->create($dataid, $storage) === false
            ) return $this->_return_message(1, 'Error saving paste. Sorry.');

            // Generate the "delete" token.
            // The token is the hmac of the pasteid signed with the server salt.
            // The paste can be delete by calling http://example.com/zerobin/?pasteid=<pasteid>&deletetoken=<deletetoken>
            $deletetoken = hash_hmac('sha1', $dataid, serversalt::get());

            // 0 = no error
            return $this->_return_message(0, $dataid, array('deletetoken' => $deletetoken));
        }

        return $this->_return_message(1, 'Server error.');
    }

    /**
     * Delete an existing paste
     *
     * @access private
     * @param  string $dataid
     * @param  string $deletetoken
     * @return void
     */
    private function _delete($dataid, $deletetoken)
    {
        // Is this a valid paste identifier?
        if (!filter::is_valid_paste_id($dataid))
        {
            $this->_error = 'Invalid paste ID.';
            return;
        }

        // Check that paste exists.
        if (!$this->_model()->exists($dataid))
        {
            $this->_error = 'Paste does not exist, has expired or has been deleted.';
            return;
        }

        // Make sure token is valid.
        serversalt::setPath($this->_conf['traffic']['dir']);
        if (!filter::slow_equals($deletetoken, hash_hmac('sha1', $dataid, serversalt::get())))
        {
            $this->_error = 'Wrong deletion token. Paste was not deleted.';
            return;
        }

        // Paste exists and deletion token is valid: Delete the paste.
        $this->_model()->delete($dataid);
        $this->_status = 'Paste was properly deleted.';
    }

    /**
     * Read an existing paste or comment
     *
     * @access private
     * @param  string $dataid
     * @return void
     */
    private function _read($dataid)
    {
        // Is this a valid paste identifier?
        if (!filter::is_valid_paste_id($dataid))
        {
            $this->_error = 'Invalid paste ID.';
            return;
        }

        // show the same error message if the paste expired or does not exist
        $genericError = 'Paste does not exist, has expired or has been deleted.';

        // Check that paste exists.
        if ($this->_model()->exists($dataid))
        {
            // Get the paste itself.
            $paste = $this->_model()->read($dataid);

            // See if paste has expired.
            if (
                isset($paste->meta->expire_date) &&
                $paste->meta->expire_date < time()
            )
            {
                // Delete the paste
                $this->_model()->delete($dataid);
                $this->_error = $genericError;
            }
            // If no error, return the paste.
            else
            {
                // We kindly provide the remaining time before expiration (in seconds)
                if (
                    property_exists($paste->meta, 'expire_date')
                ) $paste->meta->remaining_time = $paste->meta->expire_date - time();

                // The paste itself is the first in the list of encrypted messages.
                $messages = array($paste);

                // If it's a discussion, get all comments.
                if (
                    property_exists($paste->meta, 'opendiscussion') &&
                    $paste->meta->opendiscussion
                )
                {
                    $messages = array_merge(
                        $messages,
                        $this->_model()->readComments($dataid)
                    );
                }
                $this->_data = json_encode($messages);

                // If the paste was meant to be read only once, delete it.
                if (
                    property_exists($paste->meta, 'burnafterreading') &&
                    $paste->meta->burnafterreading
                ) $this->_model()->delete($dataid);
            }
        }
        else
        {
            $this->_error = $genericError;
        }
    }

    /**
     * Display ZeroBin frontend.
     *
     * @access private
     * @return void
     */
    private function _view()
    {
        // set headers to disable caching
        $time = gmdate('D, d M Y H:i:s \G\M\T');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: ' . $time);
        header('Last-Modified: ' . $time);
        header('Vary: Accept');

        // label all the expiration options
        $expire = array();
        foreach ($this->_conf['expire_options'] as $key => $value) {
            $expire[$key] = array_key_exists($key, $this->_conf['expire_labels']) ?
                $this->_conf['expire_labels'][$key] :
                $key;
        }

        $page = new RainTPL;
        $page::$path_replace = false;
        // we escape it here because ENT_NOQUOTES can't be used in RainTPL templates
        $page->assign('CIPHERDATA', htmlspecialchars($this->_data, ENT_NOQUOTES));
        $page->assign('ERROR', $this->_error);
        $page->assign('STATUS', $this->_status);
        $page->assign('VERSION', self::VERSION);
        $page->assign('OPENDISCUSSION', $this->_getMainConfig('opendiscussion', true));
        $page->assign('SYNTAXHIGHLIGHTING', $this->_getMainConfig('syntaxhighlighting', true));
        $page->assign('SYNTAXHIGHLIGHTINGTHEME', $this->_getMainConfig('syntaxhighlightingtheme', ''));
        $page->assign('NOTICE', $this->_getMainConfig('notice', ''));
        $page->assign('BURNAFTERREADINGSELECTED', $this->_getMainConfig('burnafterreadingselected', false));
        $page->assign('BASE64JSVERSION', $this->_getMainConfig('base64version', '2.1.9'));
        $page->assign('EXPIRE', $expire);
        $page->assign('EXPIREDEFAULT', $this->_conf['expire']['default']);
        $page->draw($this->_getMainConfig('template', 'page'));
    }

    /**
     * get configuration option from [main] section, optionally set a default
     *
     * @access private
     * @param  string $option
     * @param  mixed $default (optional)
     * @return mixed
     */
    private function _getMainConfig($option, $default = false)
    {
        return array_key_exists($option, $this->_conf['main']) ?
            $this->_conf['main'][$option] :
            $default;
    }

    /**
     * return JSON encoded message and exit
     *
     * @access private
     * @param  bool $status
     * @param  string $message
     * @param  array $other
     * @return string
     */
    private function _return_message($status, $message, $other = array())
    {
        $result = array('status' => $status);
        if ($status)
        {
            $result['message'] = $message;
        }
        else
        {
            $result['id'] = $message;
        }
        $result += $other;
        return json_encode($result);
    }
}