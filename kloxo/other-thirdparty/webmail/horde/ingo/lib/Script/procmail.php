<?php
/**
 * The Ingo_Script_procmail:: class represents a Procmail script generator.
 *
 * $Horde: ingo/lib/Script/procmail.php,v 1.46.10.36 2010-08-07 13:35:44 jan Exp $
 *
 * Copyright 2003-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (ASL).  If you
 * did not receive this file, see http://www.horde.org/licenses/asl.php.
 *
 * @author  Brent J. Nordquist <bjn@horde.org>
 * @author  Ben Chavet <ben@horde.org>
 * @package Ingo
 */
class Ingo_Script_procmail extends Ingo_Script {

    /**
     * The list of actions allowed (implemented) for this driver.
     *
     * @var array
     */
    var $_actions = array(
        INGO_STORAGE_ACTION_KEEP,
        INGO_STORAGE_ACTION_MOVE,
        INGO_STORAGE_ACTION_DISCARD,
        INGO_STORAGE_ACTION_REDIRECT,
        INGO_STORAGE_ACTION_REDIRECTKEEP,
        INGO_STORAGE_ACTION_REJECT
    );

    /**
     * The categories of filtering allowed.
     *
     * @var array
     */
    var $_categories = array(
        INGO_STORAGE_ACTION_BLACKLIST,
        INGO_STORAGE_ACTION_WHITELIST,
        INGO_STORAGE_ACTION_VACATION,
        INGO_STORAGE_ACTION_FORWARD
    );

    /**
     * The types of tests allowed (implemented) for this driver.
     *
     * @var array
     */
    var $_types = array(
        INGO_STORAGE_TYPE_HEADER,
        INGO_STORAGE_TYPE_BODY
    );

    /**
     * A list of any special types that this driver supports.
     *
     * @var array
     */
    var $_special_types = array(
        'Destination',
    );

    /**
     * The list of tests allowed (implemented) for this driver.
     *
     * @var array
     */
    var $_tests = array(
        'contains',
        'not contain',
        'begins with',
        'not begins with',
        'ends with',
        'not ends with',
        'regex'
    );

    /**
     * Can tests be case sensitive?
     *
     * @var boolean
     */
    var $_casesensitive = true;

    /**
     * Does the driver support the stop-script option?
     *
     * @var boolean
     */
    var $_supportStopScript = true;

    /**
     * Does the driver require a script file to be generated?
     *
     * @var boolean
     */
    var $_scriptfile = true;

    /**
     * The recipes that make up the code.
     *
     * @var array
     */
    var $_recipes = array();

    /**
     * Returns a script previously generated with generate().
     *
     * @return string  The procmail script.
     */
    function toCode()
    {
        $code = '';
        foreach ($this->_recipes as $item) {
            $code .= $item->generate() . "\n";
        }

        // If an external delivery program is used, add final rule
        // to deliver to $DEFAULT
        if (isset($this->_params['delivery_agent'])) {
            $code .= ":0 w\n";
            $code .= isset($this->_params['delivery_mailbox_prefix']) ?
                '| ' . $this->_params['delivery_agent'] . ' ' . $this->_params['delivery_mailbox_prefix'] . '$DEFAULT' :
                '| ' . $this->_params['delivery_agent'] . ' $DEFAULT';
        }

        return rtrim($code) . "\n";
    }

    /**
     * Generates the procmail script to do the filtering specified in the
     * rules.
     *
     * @return string  The procmail script.
     */
    function generate()
    {
        $filters = &$GLOBALS['ingo_storage']->retrieve(INGO_STORAGE_ACTION_FILTERS);

        $this->addItem(new Procmail_Comment(_("procmail script generated by Ingo") . ' (' . date('F j, Y, g:i a') . ')'));

        /* Add variable information, if present. */
        if (!empty($this->_params['variables']) &&
            is_array($this->_params['variables'])) {
            foreach ($this->_params['variables'] as $key => $val) {
                $this->addItem(new Procmail_Variable(array('name' => $key, 'value' => $val)));
            }
        }

        foreach ($filters->getFilterlist() as $filter) {
            switch ($filter['action']) {
            case INGO_STORAGE_ACTION_BLACKLIST:
                $this->generateBlacklist(!empty($filter['disable']));
                break;

            case INGO_STORAGE_ACTION_WHITELIST:
                $this->generateWhitelist(!empty($filter['disable']));
                break;

            case INGO_STORAGE_ACTION_VACATION:
                $this->generateVacation(!empty($filter['disable']));
                break;

            case INGO_STORAGE_ACTION_FORWARD:
                $this->generateForward(!empty($filter['disable']));
                break;

            default:
                if (in_array($filter['action'], $this->_actions)) {
                    /* Create filter if using AND. */
                    if ($filter['combine'] == INGO_STORAGE_COMBINE_ALL) {
                        $recipe = new Procmail_Recipe($filter, $this->_params);
                        if (!$filter['stop']) {
                            $recipe->addFlag('c');
                        }
                        foreach ($filter['conditions'] as $condition) {
                            $recipe->addCondition($condition);
                        }
                        $this->addItem(new Procmail_Comment($filter['name'], !empty($filter['disable']), true));
                        $this->addItem($recipe);
                    } else {
                        /* Create filter if using OR */
                        $this->addItem(new Procmail_Comment($filter['name'], !empty($filter['disable']), true));
                        $loop = 0;
                        foreach ($filter['conditions'] as $condition) {
                            $recipe = &new Procmail_Recipe($filter, $this->_params);
                            if ($loop++) {
                                $recipe->addFlag('E');
                            }
                            if (!$filter['stop']) {
                                $recipe->addFlag('c');
                            }
                            $recipe->addCondition($condition);
                            $this->addItem($recipe);
                        }
                    }
                }
            }
        }

        return $this->toCode();
    }

    /**
     * Generates the procmail script to handle the blacklist specified in
     * the rules.
     *
     * @param boolean $disable  Disable the blacklist?
     */
    function generateBlacklist($disable = false)
    {
        $blacklist = &$GLOBALS['ingo_storage']->retrieve(INGO_STORAGE_ACTION_BLACKLIST);
        $bl_addr = $blacklist->getBlacklist();
        $bl_folder = $blacklist->getBlacklistFolder();

        $bl_type = (empty($bl_folder)) ? INGO_STORAGE_ACTION_DISCARD : INGO_STORAGE_ACTION_MOVE;

        if (!empty($bl_addr)) {
            $this->addItem(new Procmail_Comment(_("Blacklisted Addresses"), $disable, true));
            $params = array('action-value' => $bl_folder,
                            'action' => $bl_type,
                            'disable' => $disable);

            foreach ($bl_addr as $address) {
                if (!empty($address)) {
                    $recipe = new Procmail_Recipe($params, $this->_params);
                    $recipe->addCondition(array('field' => 'From', 'value' => $address, 'match' => 'address'));
                    $this->addItem($recipe);
                }
            }
        }
    }

    /**
     * Generates the procmail script to handle the whitelist specified in
     * the rules.
     *
     * @param boolean $disable  Disable the whitelist?
     */
    function generateWhitelist($disable = false)
    {
        $whitelist = &$GLOBALS['ingo_storage']->retrieve(INGO_STORAGE_ACTION_WHITELIST);
        $wl_addr = $whitelist->getWhitelist();

        if (!empty($wl_addr)) {
            $this->addItem(new Procmail_Comment(_("Whitelisted Addresses"), $disable, true));
            foreach ($wl_addr as $address) {
                if (!empty($address)) {
                    $recipe = new Procmail_Recipe(array('action' => INGO_STORAGE_ACTION_KEEP, 'disable' => $disable), $this->_params);
                    $recipe->addCondition(array('field' => 'From', 'value' => $address, 'match' => 'address'));
                    $this->addItem($recipe);
                }
            }
        }
    }

    /**
     * Generates the procmail script to handle vacation.
     *
     * @param boolean $disable  Disable vacation?
     */
    function generateVacation($disable = false)
    {
        $vacation = &$GLOBALS['ingo_storage']->retrieve(INGO_STORAGE_ACTION_VACATION);
        $addresses = $vacation->getVacationAddresses();
        $actionval = array(
            'addresses' => $addresses,
            'subject' => $vacation->getVacationSubject(),
            'days' => $vacation->getVacationDays(),
            'reason' => $vacation->getVacationReason(),
            'ignorelist' => $vacation->getVacationIgnorelist(),
            'excludes' => $vacation->getVacationExcludes(),
            'start' => $vacation->getVacationStart(),
            'end' => $vacation->getVacationEnd(),
        );

        if (!empty($addresses)) {
            $this->addItem(new Procmail_Comment(_("Vacation"), $disable, true));
            $params = array('action' => INGO_STORAGE_ACTION_VACATION,
                            'action-value' => $actionval,
                            'disable' => $disable);
            $recipe = new Procmail_Recipe($params, $this->_params);
            $this->addItem($recipe);
        }
    }

    /**
     * Generates the procmail script to handle mail forwards.
     *
     * @param boolean $disable  Disable forwarding?
     */
    function generateForward($disable = false)
    {
        $forward = &$GLOBALS['ingo_storage']->retrieve(INGO_STORAGE_ACTION_FORWARD);
        $addresses = $forward->getForwardAddresses();

        if (!empty($addresses)) {
            $this->addItem(new Procmail_Comment(_("Forwards"), $disable, true));
            $params = array('action' => INGO_STORAGE_ACTION_FORWARD,
                            'action-value' => $addresses,
                            'disable' => $disable);
            $recipe = new Procmail_Recipe($params, $this->_params);
            if ($forward->getForwardKeep()) {
                $recipe->addFlag('c');
            }
            $this->addItem($recipe);
        }
    }

    /**
     * Adds an item to the recipe list.
     *
     * @param object $item  The item to add to the recipe list.
     *                      The object should have a generate() function.
     */
    function addItem($item)
    {
        $this->_recipes[] = $item;
    }

}

/**
 * The Procmail_Comment:: class represents a Procmail comment.  This is
 * a pretty simple class, but it makes the code in Ingo_Script_procmail::
 * cleaner as it provides a generate() function and can be added to the
 * recipe list the same way as a recipe can be.
 *
 * @author  Ben Chavet <ben@chavet.net>
 * @since   Ingo 1.0
 * @package Ingo
 */
class Procmail_Comment {

    /**
     * The comment text.
     *
     * @var string
     */
    var $_comment = '';

    /**
     * Constructs a new procmail comment.
     *
     * @param string $comment   Comment to be generated.
     * @param boolean $disable  Output 'DISABLED' comment?
     * @param boolean $header   Output a 'header' comment?
     */
    function Procmail_Comment($comment, $disable = false, $header = false)
    {
        if ($disable) {
            $comment = _("DISABLED: ") . $comment;
        }

        if ($header) {
            $this->_comment .= "##### $comment #####";
        } else {
            $this->_comment .= "# $comment";
        }
    }

    /**
     * Returns the comment stored by this object.
     *
     * @return string  The comment stored by this object.
     */
    function generate()
    {
        return $this->_comment;
    }

}

/**
 * The Procmail_Recipe:: class represents a Procmail recipe.
 *
 * @author  Ben Chavet <ben@chavet.net>
 * @since   Ingo 1.0
 * @package Ingo
 */
class Procmail_Recipe {

    var $_action = array();
    var $_conditions = array();
    var $_disable = '';
    var $_flags = '';
    var $_params = array(
        'date' => 'date',
        'echo' => 'echo',
        'ls'   => 'ls'
    );
    var $_valid = true;

    /**
     * Constructs a new procmail recipe.
     *
     * @param array $params        Array of parameters.
     *                               REQUIRED FIELDS:
     *                                'action'
     *                               OPTIONAL FIELDS:
     *                                'action-value' (only used if the
     *                                'action' requires it)
     * @param array $scriptparams  Array of parameters passed to
     *                             Ingo_Script_procmail::.
     */
    function Procmail_Recipe($params = array(), $scriptparams = array())
    {
        $this->_disable = !empty($params['disable']);
        $this->_params = array_merge($this->_params, $scriptparams);

        switch ($params['action']) {
        case INGO_STORAGE_ACTION_KEEP:
            // Note: you may have to set the DEFAULT variable in your
            // backend configuration.
            if (isset($this->_params['delivery_agent']) && isset($this->_params['delivery_mailbox_prefix'])) {
                $this->_action[] = '| ' . $this->_params['delivery_agent'] . ' ' . $this->_params['delivery_mailbox_prefix'] . '$DEFAULT';
            } elseif (isset($this->_params['delivery_agent'])) {
                $this->_action[] = '| ' . $this->_params['delivery_agent'] . ' $DEFAULT';
            } else {
                $this->_action[] = '$DEFAULT';
            }
            break;

        case INGO_STORAGE_ACTION_MOVE:
            if (isset($this->_params['delivery_agent']) && isset($this->_params['delivery_mailbox_prefix'])) {
                $this->_action[] = '| ' . $this->_params['delivery_agent'] . ' ' . $this->_params['delivery_mailbox_prefix'] . $this->procmailPath($params['action-value']);
            } elseif (isset($this->_params['delivery_agent'])) {
                $this->_action[] = '| ' . $this->_params['delivery_agent'] . ' ' . $this->procmailPath($params['action-value']);
            } else {
                $this->_action[] = $this->procmailPath($params['action-value']);
            }
            break;

        case INGO_STORAGE_ACTION_DISCARD:
            $this->_action[] = '/dev/null';
            break;

        case INGO_STORAGE_ACTION_REDIRECT:
            $this->_action[] = '! ' . $params['action-value'];
            break;

        case INGO_STORAGE_ACTION_REDIRECTKEEP:
            $this->_action[] = '{';
            $this->_action[] = '  :0 c';
            $this->_action[] = '  ! ' . $params['action-value'];
            $this->_action[] = '';
            $this->_action[] = '  :0' . (isset($this->_params['delivery_agent']) ? ' w' : '');
            if (isset($this->_params['delivery_agent']) && isset($this->_params['delivery_mailbox_prefix'])) {
                $this->_action[] = '  | ' . $this->_params['delivery_agent'] . ' ' . $this->_params['delivery_mailbox_prefix'] . '$DEFAULT';
            } elseif (isset($this->_params['delivery_agent'])) {
                $this->_action[] = '  | ' . $this->_params['delivery_agent'] . ' $DEFAULT';
            } else {
                $this->_action[] = '  $DEFAULT';
            }
            $this->_action[] = '}';
            break;

        case INGO_STORAGE_ACTION_REJECT:
            $this->_action[] = '{';
            $this->_action[] = '  EXITCODE=' . $params['action-value'];
            $this->_action[] = '  HOST="no.address.here"';
            $this->_action[] = '}';
            break;

        case INGO_STORAGE_ACTION_VACATION:
            require_once 'Horde/MIME.php';
            $days = $params['action-value']['days'];
            $timed = !empty($params['action-value']['start']) &&
                !empty($params['action-value']['end']);
            $this->_action[] = '{';
            foreach ($params['action-value']['addresses'] as $address) {
                if (!empty($address)) {
                    $this->_action[] = '  :0';
                    $this->_action[] = '  * ^TO_' . $address;
                    $this->_action[] = '  {';
                    $this->_action[] = '    FILEDATE=`test -f ${VACATION_DIR:-.}/\'.vacation.' . $address . '\' && '
                        . $this->_params['ls'] . ' -lcn --time-style=+%s ${VACATION_DIR:-.}/\'.vacation.' . $address . '\' | '
                        . 'awk \'{ print $6 + (' . $days * 86400 . ') }\'`';
                    $this->_action[] = '    DATE=`' . $this->_params['date'] . ' +%s`';
                    $this->_action[] = '    DUMMY=`test -f ${VACATION_DIR:-.}/\'.vacation.' . $address . '\' && '
                        . 'test $FILEDATE -le $DATE && '
                        . 'rm ${VACATION_DIR:-.}/\'.vacation.' . $address . '\'`';
                    if ($timed) {
                        $this->_action[] = '    START=' . $params['action-value']['start'];
                        $this->_action[] = '    END=' . $params['action-value']['end'];
                    }
                    $this->_action[] = '';
                    $this->_action[] = '    :0 h';
                    $this->_action[] = '    SUBJECT=| formail -xSubject:';
                    $this->_action[] = '';
                    $this->_action[] = '    :0 Whc: ${VACATION_DIR:-.}/vacation.lock';
                    if ($timed) {
                        $this->_action[] = '    * ? test $DATE -gt $START && test $END -gt $DATE';
                    }
		    $this->_action[] = '    {';
                    $this->_action[] = '      :0 Wh';
                    $this->_action[] = '      * ^TO_' . $address;
                    $this->_action[] = '      * !^X-Loop: ' . $address;
                    $this->_action[] = '      * !^X-Spam-Flag: YES';
                    if (count($params['action-value']['excludes']) > 0) {
                        foreach ($params['action-value']['excludes'] as $exclude) {
                            if (!empty($exclude)) {
                                $this->_action[] = '      * !^From.*' . $exclude;
                            }
                        }
                    }
                    if ($params['action-value']['ignorelist']) {
                        $this->_action[] = '      * !^FROM_DAEMON';
                    }
                    $this->_action[] = '      | formail -rD 8192 ${VACATION_DIR:-.}/.vacation.' . $address;
                    $this->_action[] = '      :0 eh';
                    $this->_action[] = '      | (formail -rI"Precedence: junk" \\';
                    $this->_action[] = '       -a"From: <' . $address . '>" \\';
                    $this->_action[] = '       -A"X-Loop: ' . $address . '" \\';
                    if (MIME::is8bit($params['action-value']['reason'])) {
                        $this->_action[] = '       -i"Subject: ' . MIME::encode($params['action-value']['subject'] . ' (Re: $SUBJECT)', NLS::getCharset()) . '" \\';
                        $this->_action[] = '       -i"Content-Transfer-Encoding: quoted-printable" \\';
                        $this->_action[] = '       -i"Content-Type: text/plain; charset=' . NLS::getCharset() . '" ; \\';
                        $reason = MIME::quotedPrintableEncode($params['action-value']['reason'], "\n");
                    } else {
                        $this->_action[] = '       -i"Subject: ' . MIME::encode($params['action-value']['subject'] . ' (Re: $SUBJECT)', NLS::getCharset()) . '" ; \\';
                        $reason = $params['action-value']['reason'];
                    }
                    $reason = addcslashes($reason, "\\\n\r\t\"`");
                    $this->_action[] = '       ' . $this->_params['echo'] . ' -e "' . $reason . '" \\';
                    $this->_action[] = '      ) | $SENDMAIL -f' . $address . ' -oi -t';
                    $this->_action[] = '    }';
                    $this->_action[] = '  }';
                }
            }
            $this->_action[] = '}';
            break;

        case INGO_STORAGE_ACTION_FORWARD:
            /* Make sure that we prevent mail loops using 3 methods.
             *
             * First, we call sendmail -f to set the envelope sender to be the
             * same as the original sender, so bounces will go to the original
             * sender rather than to us.  This unfortunately triggers lots of
             * Authentication-Warning: messages in sendmail's logs.
             *
             * Second, add an X-Loop header, to handle the case where the
             * address we forward to forwards back to us.
             *
             * Third, don't forward mailer daemon messages (i.e., bounces).
             * Method 1 above should make this redundant, unless we're sending
             * mail from this account and have a bad forward-to account.
             *
             * Get the from address, saving a call to formail if possible.
             * The procmail code for doing this is borrowed from the
             * Procmail Library Project, http://pm-lib.sourceforge.net/.
             * The Ingo project has the permission to use Procmail Library code
             * under Apache licence v 1.x or any later version.
             * Permission obtained 2006-04-04 from Author Jari Aalto. */
            $this->_action[] = '{';
            $this->_action[] = '  :0 ';
            $this->_action[] = '  *$ ! ^From *\/[^  ]+';
            $this->_action[] = '  *$ ! ^Sender: *\/[^   ]+';
            $this->_action[] = '  *$ ! ^From: *\/[^     ]+';
            $this->_action[] = '  *$ ! ^Reply-to: *\/[^     ]+';
            $this->_action[] = '  {';
            $this->_action[] = '    OUTPUT = `formail -zxFrom:`';
            $this->_action[] = '  }';
            $this->_action[] = '  :0 E';
            $this->_action[] = '  {';
            $this->_action[] = '    OUTPUT = $MATCH';
            $this->_action[] = '  }';
            $this->_action[] = '';

            /* Forward to each address on our list. */
            foreach ($params['action-value'] as $address) {
                if (!empty($address)) {
                    $this->_action[] = '  :0 c';
                    $this->_action[] = '  * !^FROM_MAILER';
                    $this->_action[] = '  * !^X-Loop: to-' . $address;
                    $this->_action[] = '  | formail -A"X-Loop: to-' . $address . '" | $SENDMAIL -oi -f $OUTPUT ' . $address;
                }
            }

            /* In case of mail loop or bounce, store a copy locally.  Note
             * that if we forward to more than one address, only a mail loop
             * on the last address will cause a local copy to be saved.  TODO:
             * The next two lines are redundant (and create an extra copy of
             * the message) if "Keep a copy of messages in this account" is
             * checked. */
            $this->_action[] = '  :0 E' . (isset($this->_params['delivery_agent']) ? 'w' : '');
            if (isset($this->_params['delivery_agent'])) {
                $this->_action[] = isset($this->_params['delivery_mailbox_prefix']) ?
                    ' | ' . $this->_params['delivery_agent'] . ' ' . $this->_params['delivery_mailbox_prefix'] . '$DEFAULT' :
                    ' | ' . $this->_params['delivery_agent'] . ' $DEFAULT';
            } else {
                $this->_action[] = '  $DEFAULT';
            }
            $this->_action[] = '  :0 ';
            $this->_action[] = '  /dev/null';
            $this->_action[] = '}';
            break;

        default:
            $this->_valid = false;
            break;
        }
    }

    /**
     * Adds a flag to the recipe.
     *
     * @param string $flag  String of flags to append to the current flags.
     */
    function addFlag($flag)
    {
        $this->_flags .= $flag;
    }

    /**
     * Adds a condition to the recipe.
     *
     * @param array $condition  Array of parameters. Required keys are 'field'
     *                          and 'value'. 'case' is an optional key.
     */
    function addCondition($condition = array())
    {
        $flag = !empty($condition['case']) ? 'D' : '';
        $match = isset($condition['match']) ? $condition['match'] : null;
        $string = '';
        $prefix = '';

        switch ($condition['field']) {
        case 'Destination':
            $string = '^TO_';
            break;

        case 'Body':
            $flag .= 'B';
            break;

        default:
            // convert 'field' to PCRE pattern matching
            if (!strpos($condition['field'], ',')) {
                $string = '^' . $condition['field'] . ':';
            } else {
                $string .= '^(' . str_replace(',', '|', $condition['field']) . '):';
            }
            $prefix = ' ';
        }

        $reverseCondition = false;
        switch ($match) {
        case 'regex':
            $string .= $prefix . $condition['value'];
            break;

        case 'address':
            $string .= '(.*\<)?' . preg_quote($condition['value']);
            break;

        case 'not begins with':
            $reverseCondition = true;
            // fall through
        case 'begins with':
            $string .= $prefix . preg_quote($condition['value']);
            break;

        case 'not ends with':
            $reverseCondition = true;
            // fall through
        case 'ends with':
            $string .= '.*' . preg_quote($condition['value']) . '$';
            break;

        case 'not contain':
            $reverseCondition = true;
            // fall through
        case 'contains':
        default:
            $string .= '.*' . preg_quote($condition['value']);
            break;
        }

        $this->_conditions[] = array('condition' => ($reverseCondition ? '* !' : '* ') . $string,
                                     'flags' => $flag);
    }

    /**
     * Generates procmail code to represent the recipe.
     *
     * @return string  Procmail code to represent the recipe.
     */
    function generate()
    {
        $nest = 0;
        $prefix = '';
        $text = array();

        if (!$this->_valid) {
            return '';
        }

        // Set the global flags for the whole rule, each condition
        // will add its own (such as Body or Case Sensitive)
        $global = $this->_flags;
        if (isset($this->_conditions[0])) {
            $global .= $this->_conditions[0]['flags'];
        }
        $text[] = ':0 ' . $global . (isset($this->_params['delivery_agent']) ? 'w' : '');
        foreach ($this->_conditions as $condition) {
            if ($nest > 0) {
                $text[] = str_repeat('  ', $nest - 1) . '{';
                $text[] = str_repeat('  ', $nest) . ':0 ' . $condition['flags'];
                $text[] = str_repeat('  ', $nest) . $condition['condition'];
            } else {
                $text[] = $condition['condition'];
            }
            $nest++;
        }

        if (--$nest > 0) {
            $prefix = str_repeat('  ', $nest);
        }
        foreach ($this->_action as $val) {
            $text[] = $prefix . $val;
        }

        for ($i = $nest; $i > 0; $i--) {
            $text[] = str_repeat('  ', $i - 1) . '}';
        }

        if ($this->_disable) {
            $code = '';
            foreach ($text as $val) {
                $comment = new Procmail_Comment($val);
                $code .= $comment->generate() . "\n";
            }
            return $code . "\n";
        } else {
            return implode("\n", $text) . "\n";
        }
    }

    /**
     * Returns a procmail-ready mailbox path, converting IMAP folder
     * pathname conventions as necessary.
     *
     * @param string $folder  The IMAP folder name.
     *
     * @return string  The procmail mailbox path.
     */
    function procmailPath($folder)
    {
        /* NOTE: '$DEFAULT' here is a literal, not a PHP variable. */
        if (isset($this->_params) &&
            ($this->_params['path_style'] == 'maildir')) {
            if (empty($folder) || ($folder == 'INBOX')) {
                return '$DEFAULT';
            }
            if (substr($folder, 0, 6) == 'INBOX.') {
                $folder = substr($folder, 6);
            }
            return '"$DEFAULT/.' . escapeshellcmd($folder) . '/"';
        } else {
            if (empty($folder) || ($folder == 'INBOX')) {
                return '$DEFAULT';
            }
            return str_replace(' ', '\ ', escapeshellcmd($folder));
        }
    }

}

/**
 * The Procmail_Variable:: class represents a Procmail variable.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @since   Ingo 1.0
 * @package Ingo
 */
class Procmail_Variable {

    var $_name;
    var $_value;

    /**
     * Constructs a new procmail variable.
     *
     * @param array $params  Array of parameters. Expected fields are 'name'
     *                       and 'value'.
     */
    function Procmail_Variable($params = array())
    {
        $this->_name = $params['name'];
        $this->_value = $params['value'];
    }

    /**
     * Generates procmail code to represent the variable.
     *
     * @return string  Procmail code to represent the variable.
     */
    function generate()
    {
        return $this->_name . '=' . $this->_value . "\n";
    }

}
