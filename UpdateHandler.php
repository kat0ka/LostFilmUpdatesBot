<?php

require_once(__DIR__.'/TelegramBot.php');
require_once(__DIR__.'/ConversationStorage.php');
require_once(__DIR__.'/Tracer.php');
require_once(__DIR__.'/config/stuff.php');
require_once(__DIR__.'/Botan/Botan.php');
require_once(__DIR__.'/BotPDO.php');

class UpdateHandler{
	private $tracer;
	private $memcache;
	private $botFactory;
	private $botan;
	private $logMessageQuery;

	public function __construct(TelegramBotFactoryInterface $botFactory){
		assert($botFactory !== null);
		$this->botFactory = $botFactory;
		
		$this->tracer = new Tracer(__CLASS__);
		
		$pdo = null;

		try{
			$this->memcache = createMemcache();
			$pdo = BotPDO::getInstance();
		}
		catch(Exception $ex){
			$this->tracer->logException('[ERROR]', $ex);
			throw $ex;
		}

		$this->botan = new Botan(BOTAN_API_KEY);

		$this->logMessageQuery = $pdo->prepare("
			INSERT INTO `messagesHistory` (direction, chat_id, text)
			VALUES ('INCOMING', :chat_id, :text)
		");
	}

	private function getLastUpdateId(){ // TODO: move to the DB in order to be able to lock it
		return intval($this->memcache->get(MEMCACHE_LATEST_UPDATE_ID_KEY));
	}

	private function setLastUpdateId($value){
		assert(is_int($value));

		$current = $this->getLastUpdateId();
		if($value <= $current){
			$this->tracer->logError('[ERROR]', __FILE__, __LINE__, "New update_id($value) is less or equal with current($current)");
			throw new RuntimeException("New update_id($value) is less than current($current)");
		}

		$this->memcache->set(MEMCACHE_LATEST_UPDATE_ID_KEY, $value);
	}

	private function verifyUpdateId($update_id){
		assert(is_int($update_id));
		return IGNORE_UPDATE_ID || $update_id > $this->getLastUpdateId();
	}

	private static function validateFields($update){
		return
			isset($update->update_id)			&&
			isset($update->message)				&&
			isset($update->message->from)		&&
			isset($update->message->from->id)	&&
			isset($update->message->chat)		&&
			isset($update->message->chat->id)	&&
			isset($update->message->text);
	}

	private static function normalizeFields($update){
		$result = clone $update;
		
		$result->update_id = intval($result->update_id);
		$result->message->from->id = intval($result->message->from->id);
		$result->message->chat->id = intval($result->message->chat->id);

		return $result;
	}

	private function extractCommand($text){
		$regex = '/(\/\w+)/';
		$matches = array();
		$res = preg_match($regex, $text, $matches);
		if($res === false){
			$this->tracer->logError('[ERROR]', __FILE__, __LINE__, 'preg_match error: '.preg_last_error());
			throw new LogicException('preg_match error: '.preg_last_error());
		}
		if($res === 0){
			$this->tracer->logError('[ERROR]', __FILE__, __LINE__, "Invalid command '$text'");
			return $text;
		}

		return $matches[1];
	}

	private function verifyData($update){
		return $this->verifyUpdateId($update->update_id);
	}

	private function sendToBotan($message, $event){
		$message_assoc = json_decode(json_encode($message), true);
		$this->botan->track($message_assoc, $event);
	}

	private static function extractUserInfo($message){
		$chat = $message->chat;

		return array(
			'username'		=> isset($chat->username)	? $chat->username	: null,
			'first_name' 	=> isset($chat->first_name)	? $chat->first_name	: null,
			'last_name' 	=> isset($chat->last_name)	? $chat->last_name	: null
		);
	}


	private function handleMessage($message){
		try{
			$this->logMessageQuery->execute(
				array(
					':chat_id'	=> $message->chat->id,
					':text'		=> $message->text
				)
			);
		}
		catch(PDOException $ex){
			$this->tracer->logException('[DB ERROR]', $ex);
			$this->tracer->logError('[DB ERROR]', __FILE__, __LINE__, PHP_EOL.print_r($message, true));
		}

		$firstMessage = null;
		try{
			$conversationStorage = new ConversationStorage($message->from->id);
			
			if($conversationStorage->getConversationSize() === 0){
				$message->text = $this->extractCommand($message->text);
			}
			
			$conversationStorage->insertMessage($message->text);
			$firstMessage = $conversationStorage->getFirstMessage();

			$bot = $this->botFactory->createBot($message->chat->id);
			$bot->incomingUpdate($conversationStorage, self::extractUserInfo($message));
		}
		catch(TelegramException $ex){
			$this->tracer->logException('[TELEGRAM EXCEPTION]', $ex);
			$ex->release();
		}
		catch(Exception $ex){
			$this->tracer->logException('[ERROR]', $ex);
		}
		
		try{
			$this->sendToBotan($message, $firstMessage);
		}
		catch(Exception $ex){
			$this->tracer->logException('[BOTAN ERROR]', $ex);
		}
	}

	public function handleUpdate($update){
		if(self::validateFields($update) === false){
			$this->tracer->logError('[DATA ERROR]', __FILE__, __LINE__, 'Update is invalid:'.PHP_EOL.print_r($update, true));
			throw new RuntimeException('Invalid update');
		}

		$update = self::normalizeFields($update);

		if($this->verifyData($update) === false){
			$this->tracer->logError('[ERROR]', __FILE__, __LINE__, 'Invalid update data: Last id: '.$this->getLastUpdateId().PHP_EOL.print_r($update, true));
			throw new RuntimeException('Invalid update data'); // TODO: check if we should gently skip in such case
		}

		$this->handleMessage($update->message);
	}

}










