<?php

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;

include_once __DIR__ . '/../dbhelpers/resource_dbhelper.php';

class resource_listener_conver extends Conversation {

	const TYPE_RESOURCE = 1;
	const TYPE_URL = 2;
	const TYPE_ASSIGN = 3;
	
	/** @var string */
	protected $name;

	/** @var string */
	protected $type;

	/** @var string */
	protected $course;

	public function ask_name() {
		$question = Question::create(get_string('fullaskresourcename', 'block_xatbot'))
        ->addButtons([
			Button::create(get_string('buttonall', 'block_xatbot'))->value(get_string('buttonall', 'block_xatbot'))
		]);

		$this->ask($question, function (Answer $answer) {
			$this->name = null;
			if (strcasecmp($answer->getText(), get_string('buttonall', 'block_xatbot')) != 0) {
				$this->name = $answer->getText(); 
			}

			$this->ask_type();
		});
	}

	public function ask_type() {
		
		$question = Question::create(get_string('fullaskresourcetype', 'block_xatbot'))
        ->addButtons([
            Button::create(get_string('pluginname', 'mod_resource'))->value(get_string('pluginname', 'mod_resource')),
			Button::create(get_string('pluginname', 'mod_url'))->value(get_string('pluginname', 'mod_url')),
			Button::create(get_string('buttonall', 'block_xatbot'))->value(get_string('buttonall', 'block_xatbot'))
        ]);

		$this->ask($question, function (Answer $answer) {
			$this->type = null;
			if (strcasecmp($answer->getText(), get_string('pluginname', 'mod_resource')) == 0) {
				$this->type = 'resource';
			} elseif (strcasecmp($answer->getText(), get_string('pluginname', 'mod_url')) == 0) {
				$this->type = 'url';
			}

			$this->ask_course();
		});
	}

	public function ask_course() {
		$question = Question::create(get_string('fullaskresourcecourse', 'block_xatbot'))
        ->addButtons([
			Button::create(get_string('buttonall', 'block_xatbot'))->value(get_string('buttonall', 'block_xatbot'))
		]);
		
		$this->ask($question, function (Answer $answer) {
			$this->course = null;
			if (strcasecmp($answer->getText(), get_string('buttonall', 'block_xatbot')) != 0) {
				$this->course = $answer->getText(); 
			}
			$this->manage_reply();
		});
	}

	public function manage_reply() {
		$rs_res = null;
		$rs_url = null;
		$rs_asg = null;

		switch ($this->type) {
			case 'resource':
				$rs_res = resource_dbhelper::search_resource_files($this->name, $this->course);
				break;
			
			case 'url':
				$rs_url = resource_dbhelper::search_resource_url($this->name, $this->course);
				break;
			
			default:
				$rs_res = resource_dbhelper::search_resource_files($this->name, $this->course);
				$rs_url = resource_dbhelper::search_resource_url($this->name, $this->course);
				$rs_asg = resource_dbhelper::search_resource_assign($this->name, $this->course);

		}

		if ($rs_res->valid()) {
			$this->say(get_string('fullresourcematch', 'block_xatbot', get_string('pluginname', 'mod_resource')));
			$this->create_messages($rs_res, TYPE_RESOURCE);
		}

		if ($rs_url->valid()) {
			$this->say(get_string('fullresourcematch', 'block_xatbot', get_string('pluginname', 'mod_url')));
			$this->create_messages($rs_url, TYPE_URL);
		}
		
		if ($rs_asg->valid()) {
			$this->say(get_string('fullresourcematch', 'block_xatbot', get_string('pluginname', 'mod_assign')));
			$this->create_messages($rs_asg, TYPE_ASSIGN);
		}

		if (!$rs_res->valid() && !$rs_url->valid() && !$rs_asg->valid()) {
			$this->say(get_string('fullnoresourcematch', 'block_xatbot'));
		}

        global $PAGE;
        $context = context::instance_by_id($_GET['context']);
        if ($context->get_course_context(false) 
                && (is_viewing(context::instance_by_id($_GET['context'])) 
                || is_enrolled(context::instance_by_id($_GET['context'])))) {
            $event = \block_xatbot\event\resource_searched::create(array(
                'context' => context::instance_by_id($_GET['context']), 
            ));
        } else {
            $event = \block_xatbot\event\resource_searched::create(array(
                'context' => $PAGE->context, 
            ));
        }

        $event->trigger();

	}

	public function create_messages($rs, $type) {
		global $CFG, $USER;
		$aux = false;
		foreach ($rs as $record) {
            if ($record->visible 
                    || has_capability('moodle/course:viewhiddenactivities', context_course::instance($record->course))) {
				
				//Send Resource name with link
				$url = '';
				switch ($type) {
					case TYPE_RESOURCE: 
						$url = $CFG->wwwroot . '/pluginfile.php/' . $record->cid . '/mod_resource/content/' 
							. $record->revision . '/' . $record->filename;
						break;

					case TYPE_URL:
						$url = $record->externalurl;
						break;

					case TYPE_ASSIGN: 
						$url = $CFG->wwwroot . '/mod/assign/view.php?id=' . $record->id;
						break;

				}
				$attachment = new File($url, [
					'custom_payload' => true,
				]);
                $message = OutgoingMessage::create($record->name)
                    ->withAttachment($attachment);
				$this->say($message);
				
				//Send ' - course: ' separator
				$this->say(get_string('compresourcematchcourse', 'block_xatbot'));

				//Send Course name with link
				$attachment = new File($CFG->wwwroot . '/course/view.php?id=' . $record->course, [
                    'custom_payload' => true,
                ]);
                $message = OutgoingMessage::create($record->fullname)
                    ->withAttachment($attachment);
                $this->say($message);
            }
		}
		$rs->close();
	}

	public function run() {
		$this->ask_name();
	}

}