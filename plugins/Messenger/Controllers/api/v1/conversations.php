<?php
/**
 * Minds Newsfeed API
 *
 * @version 1
 * @author Mark Harding
 */
namespace Minds\Plugin\Messenger\Controllers\api\v1;

use Minds\Core;
use Minds\Core\Session;
use Minds\Core\Security;
use Minds\Entities;
use Minds\Helpers;
use Minds\Plugin\Messenger;
use Minds\Interfaces;
use Minds\Api\Factory;
use Minds\Core\Sockets;

class conversations implements Interfaces\Api
{

    /**
     * Returns the conversations or conversation
     * @param array $pages
     *
     * API:: /v1/conversations
     */
    public function get($pages)
    {

        Factory::isLoggedIn();

        $response = [];

        if(isset($pages[0])){
            $response = $this->getConversation($pages);
        } else {
            $response = $this->getList();
        }


        return Factory::response($response);

    }

    private function getConversation($pages)
    {
        $response = [];

        $me = Core\Session::getLoggedInUser();

        $conversation = (new Messenger\Entities\Conversation())
          ->loadFromGuid($pages[0]);
        $messages = (new Messenger\Core\Messages)
          ->setConversation($conversation);

        if ($conversation) {
            $response = $conversation->export();
            $blocked = false;

            if (is_array($response['participants'])) {
                foreach ($response['participants'] as $participant) {
                    if (!Security\ACL::_()->interact(Core\Session::getLoggedInUser(), $participant['guid'])) {
                        $blocked = true;
                        break;
                    }
                }
            }

            $response['blocked'] = $blocked;
        }

        $limit = isset($_GET['limit']) ? $_GET['limit'] : 6;
        $offset = isset($_GET['offset']) ? $_GET['offset'] : "";
        $finish = isset($_GET['finish']) ? $_GET['finish'] : "";
        $messages = $messages->getMessages($limit, $offset, $finish);

        if($messages){

            foreach($messages as $k => $message){
              $_GET['decrypt'] = true;
                if(isset($_GET['decrypt']) && $_GET['decrypt']){
                    $messages[$k]->decrypt(Core\Session::getLoggedInUser(), urldecode($_GET['password']));
                } else {
                    //support legacy clients
                    //$key = "message:$me->guid";
                    //$messages[$k]->message = $messages[$k]->$key;
                }
            }

            $conversation->markAsRead(Session::getLoggedInUserGuid());

            $messages = array_reverse($messages);
            $response['messages'] = Factory::exportable($messages);
            $response['load-next'] = (string) end($messages)->guid;
            $response['load-previous'] = (string) reset($messages)->guid;
        }

        $keystore = new Messenger\Core\Keystore();

        if(!$offset){
            //return the public keys
            $response['publickeys'] = [];
            foreach($conversation->getParticipants() as $participant_guid){
                if($participant_guid == Session::getLoggedInUserGuid()){
                    continue;
                }
                $response['publickeys'][(string) $participant_guid] = $keystore->setUser($participant_guid)->getPublicKey();
            }
        }

        return $response;
    }

    private function getList()
    {
        $response = [];

        $conversations = (new Messenger\Core\Conversations)->getList(12, $_GET['offset']);

        if($conversations){
            $response['conversations'] = Factory::exportable($conversations);

            //mobile polyfill
            foreach($response['conversations'] as $k => $v){
                $response['conversations'][$k]['subscribed'] = true;
                $response['conversations'][$k]['subscriber'] = true;
            }

            end($conversations);
            $response['load-next'] = (int) $_GET['offset'] + count($conversations);
            $response['load-previous'] = (int) $_GET['offset'] - count($conversations);
        }
        return $response;
    }

    public function post($pages){
        Factory::isLoggedIn();

        //error_log("got a message to send");
        $conversation = new Messenger\Entities\Conversation();
        if(strpos($pages[0], ':') === FALSE){ //legacy messages get confused here
            $conversation->setParticipant(Core\Session::getLoggedInUserGuid())
              ->setParticipant($pages[0]);
        } else {
            $conversation->setGuid($pages[0]);
        }

        $message = (new Messenger\Entities\Message())
          ->setConversation($conversation);

        if(isset($_POST['encrypt']) && $_POST['encrypt']){
            $message->setMessage($_POST['message']);
            $message->encrypt();
        } else {
            $message->client_encrypted = true;
            foreach($conversation->getParticipants() as $guid){
                $msg = base64_encode(base64_decode(rawurldecode($_POST[$key]))); //odd bug sometimes with device base64..
                $message->setMessages($guid, $msg, true);
            }
        }

        $message->save();
        $conversation->markAsUnread(Session::getLoggedInUserGuid());
        $conversation->markAsRead(Session::getLoggedInUserGuid());

        /*if($message->client_encrypted){
          $key = "message:".elgg_get_logged_in_user_guid();
          $message->message = $message->$key;
        } else {
          $key = "message";
          $message->message = $_POST['message'];
        }*/
        $response["message"] = $message->export();
        $emit = $response['message'];
        unset($emit['message']);

        try {
            (new Sockets\Events())
              ->to($conversation->buildSocketRoomName())
              ->emit('pushConversationMessage', (string) $conversation->getGuid(), $emit);
        } catch (\Exception $e) { /* TODO: To log or not to log */ }

        $this->emitSocketTouch($conversation);

        return Factory::response($response);
    }

    public function put($pages){
        Factory::isLoggedIn();

        switch($pages[0]){
            case 'call':
               \Minds\Core\Queue\Client::build()->setExchange("mindsqueue")
                                                ->setQueue("Push")
                                                ->send(array(
                                                     "user_guid"=>$pages[1],
                                                    "message"=> \Minds\Core\Session::getLoggedInUser()->name . " is calling you.",
                                                    "uri" => 'call',
                                                    "sound" => 'ringing-1.m4a',
                                                    "json" => json_encode(array(
                                                        "from_guid"=>\Minds\Core\Session::getLoggedInUser()->guid,
                                                        "from_name"=>\Minds\Core\Session::getLoggedInUser()->name
                                                    ))
                                                ));
                break;
            case 'no-answer':
              //leave a notification
              $conversation = new entities\conversation(elgg_get_logged_in_user_guid(), $pages[1]);
              $message = new entities\CallMissed($conversation);
              $message->save();
              $conversation->update();
              Core\Queue\Client::build()->setExchange("mindsqueue")
                                        ->setQueue("Push")
                                        ->send(array(
                                              "user_guid"=>$pages[1],
                                              "message"=> \Minds\Core\Session::getLoggedInUser()->name . " tried to call you.",
                                              "uri" => 'chat',

                                             ));
              break;
            case 'ended':
              $conversation = new entities\conversation(elgg_get_logged_in_user_guid(), $pages[1]);
              $message = new entities\CallEnded($conversation);
              $message->save();
              break;
        }

        return Factory::response(array());

    }

    public function delete($pages){
        Factory::isLoggedIn();

        $user = Core\Session::getLoggedInUser();
        $response = [];

        $conversation = new Messenger\Entities\Conversation();
        if(strpos($pages[0], ':') === FALSE){ //legacy messages get confused here
            $conversation->setParticipant($user)
              ->setParticipant($pages[0]);
        } else {
            $conversation->setGuid($pages[0]);
        }

        $message = (new Messenger\Entities\Message())
          ->setConversation($conversation);

        $message->deleteAll();
        $conversation->saveToLists();

        try {
            (new Sockets\Events())
              ->to($conversation->buildSocketRoomName())
              ->emit('clearConversation', (string) $conversation->getGuid(), [
                  'guid' => (string) $user->guid,
                  'name' => $user->name
              ]);
        } catch (\Exception $e) { /* TODO: To log or not to log */ }

        $this->emitSocketTouch($conversation);

        return Factory::response($response);
    }

    private function emitSocketTouch($conversation)
    {
        if ($conversation->getParticipants()) {
            $messenger_rooms = [];

            foreach ($conversation->getParticipants() as $guid) {
                if ($guid == Core\Session::getLoggedInUserGuid()) {
                    continue;
                }

                $messenger_rooms[] = "messenger:{$guid}";
            }

            try {
                (new Sockets\Events())
                ->to($messenger_rooms)
                ->emit('touchConversation', (string) $conversation->getGuid());
            } catch (\Exception $e) { /* TODO: To log or not to log */ }
        }
    }

}
