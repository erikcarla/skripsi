<?php

require 'Slim/Slim.php';
require 'Slim/class.phpmailer.php';

session_start();

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$mongo = new MongoClient();

$app->post("/login", function() use ($app, $mongo) {
	$request = $app->request();
	$data = json_decode($request->getBody());
	$db = $mongo->CodeEditor;
	$userCollection = $db->User;
	$fileCollection = $db->File;
	$logCollection = $db->Log;
	$result = $userCollection->find(array("_id" => $data->userid))->count();

	if ($result == 0) {
		$userCollection->update(
      array("_id" => $data->userid),
      array(
        "_id" => $data->userid,
        "userName" => $data->username,
        "email" => $data->useremail,
        "picture" => $data->userimage,
        "isLogin" => 0
      ),
		  array("upsert" => true)
    );
		$rootid = new MongoId();
		$fileCollection->batchInsert(array(
			array(
				"_id" => $rootid,
				"name" => $data->username,
				"ext" => "#",
				"isParent" => "1",
				"owner" => $data->userid,
				"fileType" => "folder",
				"access" => "root",
				"userLists" => array(
					array(
						"parent" => array("#"),
						"userid" => $data->userid
					)
				)
			),
			array(
				"name" => "Own Folder",
				"ext" => "#",
				"isParent" => "0",
				"owner" => $data->userid,
				"fileType" => "folder",
				"access" => "restricted-public",
				"userLists" => array(
					array(
						"parent" => array($rootid->{'$id'}),
						"userid" => $data->userid
					)
				)
			),
			array(
				"name" => "Share",
				"ext" => "#",
				"isParent" => "0",
				"owner" => $data->userid,
				"fileType" => "folder",
				"access" => "restricted",
				"userLists" => array(
					array(
						"parent" => array($rootid->{'$id'}),
						"userid" => $data->userid
					)
				)
			)
		));
	}

	$dateIn = date("D M j G:i:s T Y");
  $userCollection->update(
    array("_id" => $data->userid),
    array('$set' => array(
      "isLogin" => 1
    ))
  );

	if (!isset($_SESSION["isLogin"])) {
		$logCollection->insert(array(
			"userId" => $data->userid,
			"userIn" => $dateIn,
			"userOut" => null
		));
	}
  $_SESSION["userId"] = $data->userid;
  $_SESSION["userIn"] = $dateIn;
  $_SESSION["isLogin"] = 1;
});

$app->get("/disconnected/:id", function($id) use ($app, $mongo) {
  $db = $mongo->CodeEditor;
  $userCollection = $db->User;
  $userCollection->update(
    array("_id" => $id),
    array('$set' => array(
      "isLogin" => 0
    ))
  );
  if($_SESSION["userId"] == 'Anonymous'){

    $result = $fileCollection->find(array("userLists.userid" => $_SESSION["userId"]));

    foreach ($result as $doc){
      $chatCollection->remove(array("fileId" => $doc['_id']->{'$id'}));
    }

    $userCollection->remove(array("_id" => $_SESSION["userId"]));
    $logCollection->remove(array("userId" => $_SESSION["userId"]));
    $fileCollection->remove(array("userLists.userid" => $_SESSION["userId"]));
  }
  $_SESSION["isLogin"] = 0;
});

$app->post("/logout", function() use ($app, $mongo) {
	$db = $mongo->CodeEditor;
	$logCollection = $db->Log;
	$userid = $_SESSION["userId"];
	$logCollection->update(
		array('$and' => array(
			array("userId" => $_SESSION["userId"]),
			array("userOut" => null)
		)),
		array('$set' => array(
			"userOut" => date("D M j G:i:s T Y")
		))
	);

  $userCollection = $db->User;
  $userCollection->update(
    array("_id" => $_SESSION["userId"]),
    array('$set' => array(
      "isLogin" => 0
    ))
  );

	$fileCollection = $db->File;
	$chatCollection = $db->Chat;
  if($_SESSION["userId"] == 'Anonymous'){

    $result = $fileCollection->find(array("userLists.userid" => $_SESSION["userId"]));

    foreach ($result as $doc){
      $chatCollection->remove(array("fileId" => $doc['_id']->{'$id'}));
    }

    $userCollection->remove(array("_id" => $_SESSION["userId"]));
    $logCollection->remove(array("userId" => $_SESSION["userId"]));
    $fileCollection->remove(array("userLists.userid" => $_SESSION["userId"]));
  	}
	session_destroy();

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode(array("userId" => $userid)));
});

$app->get("/compiling/:fileid", function($fileid) use ($app, $mongo) {
	$db1 = $mongo->Collaboration;
	$docsCollection = $db1->docs;
	$result = $docsCollection->find(array("_id" => $fileid));

	$db2 = $mongo->CodeEditor;
	$fileCollection = $db2->File;
	$resultFile = $fileCollection->find(array("_id" => new MongoId($fileid)));

	$ext = "";
	foreach ($resultFile as $doc) $filename = $doc["_id"]->{'$id'} . "." .$doc["ext"];
	$code = "";
	foreach ($result as $doc) $code = $doc["data"]["snapshot"];
	$file = fopen("../compile/" . $filename, "w") or die("Cannot open file");
	fwrite($file, $code);
	fclose($file);
	chmod("../compile/". $filename, 0777);

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode(array("file" => $filename)));
});

$app->post("/addCollaborators", function() use ($app, $mongo) {
	$post = $app->request()->getBody();
	$data = json_decode($post);
	$db = $mongo->CodeEditor;
	$fileCollection = $db->File;
  $userCollection = $db->User;

	$ret = $fileCollection->find(
		array('$and' => array(
			array("owner" => $data->userid),
			array("name" => "Share")
		))
	);
	$shareFolder = "";
  $userExists = 0;

	foreach ($ret as $doc) $shareFolder = $doc["_id"]->{'$id'};

  $ret = $fileCollection->find(array("_id" => new MongoId($data->fileid)));
  foreach ($ret as $doc) {
    for ($i = 0; $i < count($doc["userLists"]); ++$i) {
      if ($doc["userLists"][$i]["userid"] == $data->userid) {
        $userExists = 1;
        break;
      }
    }
  }

  if ($userExists == 0) {
    $fileCollection->update(
      array("_id" => new MongoId($data->fileid)),
      array('$push' => array(
        "userLists" => array(
          "parent" => array($shareFolder, "#"),
          "userid" => $data->userid
        )
      ))
    );

    $tmpUser = $userCollection->find(array("_id" => $data->userid));
    $statusOnline = "";
    foreach ($tmpUser as $doc) $statusOnline = $doc["isLogin"];
    $data = array("status" => "success", "statusOnline" => $statusOnline);
  } else $data = array("status" => "failed", "message" => "User Exists");

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($data));
});

$app->post("/removeCollaborators", function() use ($app, $mongo) {
	$post = $app->request()->getBody();
	$data = json_decode($post);
	$db = $mongo->CodeEditor;
	$fileCollection = $db->File;
	$fileCollection->update(
		array("_id" => new MongoId($data->fileid)),
		array('$pull' => array(
			"userLists" => array(
				"userid" => $data->userid
			)
		))
	);
});

$app->get("/getFileContent/:nodeid", function($nodeid) use($app, $mongo) {
	$db = $mongo->CodeEditor;
	$c = $db->File;
	$userCollection = $db->User;
	$res = $c->find(array("_id" => new MongoId($nodeid)));

	$fileData = array();

  foreach ($res as $doc) {
		array_push($fileData, array(
			"fileid" => $doc["_id"]->{'$id'},
			"filename" => $doc["name"] . "." . $doc["ext"],
			"fileext" => $doc["ext"],
      "isOwner" => ($_SESSION["userId"] == $doc["owner"]) ? 1 : 0
		));
	}

  $response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($fileData));
});

$app->post("/insertFileContent/:nodeid", function($nodeid) use($app, $mongo) {
	$db1 = $mongo->Collaboration;
	$docsCollection = $db1->docs;
	$post = $app->request()->getBody();
	$data = json_decode($post);

	$param = array(
		"_id" => $nodeid,
		"data" => array(
			"v" => 1,
			"meta" => array(
				"mtime" => $data->date,
				"ctime" =>  $data->date
			),
			"snapshot" => $data->content,
			"type" => "text"
		),
	);
	$docsCollection->insert($param);
});


$app->get("/getCollaboratorsList/:nodeid", function($nodeid) use ($app, $mongo) {
  $db = $mongo->CodeEditor;
  $fileCollection = $db->File;
  $userCollection = $db->User;
  $res = $fileCollection->find(array("_id" => new MongoId($nodeid)));

  $collaboratorsData = array();
	$userLists = array();
  foreach ($res as $doc) {
    for ($i = 0; $i < count($doc["userLists"]); ++$i)
			if ($doc["userLists"][$i]["userid"] != $_SESSION["userId"])
				array_push($userLists, $doc["userLists"][$i]["userid"]);
	}
  $users = $userCollection->find(array("_id" => array('$in' => $userLists)));
	foreach ($users as $docs) array_push($collaboratorsData, $docs);

  $response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($collaboratorsData));
});

$app->get("/getFolderUser", function() use($app, $mongo) {
	$db = $mongo->CodeEditor;
	$c = $db->File;
	$list = $c->find(array("userLists.userid" => $_SESSION["userId"]));
	$folderList = array();
	foreach ($list as $doc) {
		$filename = $doc["name"] . (($doc["ext"] != "#") ? "." . $doc["ext"] : "");
		$icon = "jstree-" . $doc["fileType"];
		$parent = "";
		for ($i = 0; $i < count($doc["userLists"]); ++$i) {
			if ($doc["userLists"][$i]["userid"] == $_SESSION["userId"])
				$parent = $doc["userLists"][$i]["parent"][0];
		}
		array_push($folderList, array(
			"id" => $doc["_id"]->{'$id'},
			"parent" => $parent,
			"text" => $filename,
			"icon" => $icon,
			"type" => $doc["fileType"],
			"data" => array(
				"access" => $doc["access"],
				"owner" => ($doc["owner"] == $_SESSION["userId"]) ? 1 : 0
			)
		));
	}

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($folderList));
});

$app->post("/findFileName", function() use($app, $mongo) {
	$post = $app->request()->getBody();
	$data = json_decode($post);
	$db = $mongo->CodeEditor;
	$fileCollection = $db->File;
	$isExists = $fileCollection->find(array('$and' => array(
    array('$and' => array(
      array("name" => $data->name),
      array("ext" => $data->ext),
	  )),
    array("owner" => $_SESSION['userId'])
  )))->count();

	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($isExists));
});

$app->post("/newNode", function() use($app, $mongo) {
	$post = $app->request()->getBody();
	$data = json_decode($post);
	$db = $mongo->CodeEditor;
	$fileCollection = $db->File;
	$result = array();

	if (isset($data->previd)) {
		$res = $fileCollection->find(array("_id" => new MongoId($data->previd)));
		foreach ($res as $doc) {
			for ($i = 0; $i < count($doc["userLists"]); ++$i) {
				$d = $doc["userLists"][$i];
				if ($d["userid"] == $_SESSION["userId"])
					$doc["userLists"][$i]["parent"] = $data->parents;
			}
			$param = array(
				"name" => $data->name,
				"ext" => $doc["ext"],
				"isParent" => "0",
				"owner" => $_SESSION["userId"],
				"access" => "private",
				"fileType" => $doc["fileType"],
				"userLists" => $doc["userLists"]
			);
			// print_r($param); die();
		}
		$fileCollection->update(array("_id" => new MongoId($data->fileid)), $param);
	} else {
		$param = array(
			"name" => $data->name,
			"ext" => $data->ext,
			"isParent" => "0",
			"owner" => $_SESSION["userId"],
			"access" => "private",
			"fileType" => $data->fileType,
			"userLists" => array(
				array(
					"parent" => $data->parents,
					"userid" => $_SESSION["userId"]
				)
			)
		);
		$fileCollection->insert($param);
	}
	$fileCollection->update(
		array("_id" => new MongoId($data->parents[0])),
		array('$set' => array(
				"isParent" => "1"
	)));


	$response = $app->response();
	if(isset($data->fileid))
		$response->body(json_encode($data->fileid));
	else
		$response->body(json_encode($param['_id']));
});

$app->get("/deleteNode/:id", function($id) use($app, $mongo) {
	$db = $mongo->CodeEditor;
	$c = $db->File;
	$where = array('$or' => array(
			array("_id" => new MongoId($id)),
			array("parent" => $id)
		));
	$c->remove($where);

  $db1 = $mongo->Collaboration;
  $docs = $db1->docs;
  $ops = $db1->selectCollection("ops." . $id);
  $docs->remove(array("_id" => $id));
  $ops->drop();
});

$app->post("/moveNode", function() use ($app, $mongo) {
	$post = $app->request()->getBody();
	$data = json_decode($post);
  $db = $mongo->CodeEditor;
  $fileCollection = $db->File;

  // update current node
  $where = array('$and' => array(
      array("_id" => new MongoId($data->fileid)),
      array("userLists.userid" => $_SESSION["userId"])
  ));
  $fileCollection->update(
    $where,
    array('$set' => array(
        'userLists.$.parent' => $data->parents
    ))
  );

  // update child node
  $files = $fileCollection->find(array("userLists.parent" => $data->fileid));

  // merging current node id and it parents
  $parents = array_merge(array($data->fileid), $data->parents);
  foreach ($files as $docs) {
    $where = array('$and' => array(
        array("_id" => new MongoId($docs["_id"]->{'$id'})),
        array("userLists.userid" => $_SESSION["userId"])
    ));
    // search current node position in child parents
    $listParents = array();
    for ($i = 0; $i < count($docs["userLists"][0]["parent"]); ++$i) {
      if ($docs["userLists"][0]["parent"][$i] == $data->fileid) break;
      array_push($listParents, $docs["userLists"][0]["parent"][$i]);
    }
    $parents = array_merge($listParents, $parents);

    $fileCollection->update(
      $where,
      array('$set' => array(
          'userLists.$.parent' => $parents
      ))
    );
  }
});

$app->get("/downloadNode/:id", function($id) use($app, $mongo) {
	$db = $mongo->CodeEditor;
	$c = $db->File;
	$where = array('$or' => array(
			array("_id" => new MongoId($id)),
			array("userLists.parent" => $id)
		));
	$res = $c->find($where);

	$parent = array();
	$child = array();
	$maxParent = -1;
	foreach ($res as $doc) {
		$filename = $doc["name"] . (($doc["ext"] != "#") ? "." . $doc["ext"] : "");
		$p = "";
		for ($i = 0; $i < count($doc["userLists"]); ++$i) {
			if ($doc["userLists"][$i]["userid"] == $_SESSION["userId"])
				$p = $doc["userLists"][$i]["parent"][0];
		}
		@$parent[$doc["_id"]->{'$id'}] = array($p, $filename);
		if ($doc["isParent"] == "0")
			array_push($child, array(
					"id" => $doc["_id"]->{'$id'},
					"type" => $doc["fileType"]
			));
	}

	exec("cd download_code && rm " . $_SESSION['userId'] . ".zip");
	$baseFolder = "";
	for ($i = 0; $i < count($child); ++$i) {
		$startNode = ($child[$i]["type"] == "file") ? $parent[$child[$i]["id"]][0] : $child[$i]["id"];
		$path = "";
		while (isset($parent[$startNode][0])) {
			if ($path == "") $path = $parent[$startNode][1];
			else
				if (isset($parent[$startNode][1]))
					$path = $parent[$startNode][1] . '/' . $path;

			$startNode = $parent[$startNode][0];
		}

		$newpath = str_replace(" ", "_", $path);
    $explodingPath = explode("/", $newpath);
		$baseFolder = ($newpath != "") ? $explodingPath[0] : $parent[$child[$i]["id"]][1];
		$old_umask = umask(0);
		if (!file_exists("download_code/" . $newpath) && $newpath != "")
			mkdir("download_code/" . $newpath, 0777, true);
		umask($old_umask);
		if ($child[$i]["type"] == "file") {
			$filepath = dirname(__FILE__) . "/download_code/" . $newpath . "/" . $parent[$child[$i]["id"]][1];
			$file = fopen($filepath, "w");
			$db1 = $mongo->Collaboration;
			$c1 = $db1->docs;
			$result = $c1->find(array("_id" => $child[$i]["id"]));
			$txt = "";
			foreach ($result as $doc) $txt = $doc["data"]["snapshot"];
			fwrite($file, $txt);
			fclose($file);
		}
	}
	exec("cd download_code && zip -r " . $_SESSION['userId'] . ".zip $baseFolder && rm -rf $baseFolder");
	// exec("cd download_code && rm $baseFolder");
	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode(array("status" => "success", "downloadid" => $_SESSION['userId'])));
});

$app->post("/uploadNode", function() use ($app) {
	$app->response()->header("Content-Type","application/json");
	$uploaddir="./upload/";
	foreach ($_FILES as $file)
	{
		if(move_uploaded_file($file['tmp_name'],$uploaddir.basename($file['name']) )){
			$content = file_get_contents($uploaddir.basename($file['name']));
			$filename = $file['name'];
			$data = array(
				"content" => $content,
				"filename" => $filename
			);
			unlink($uploaddir.basename($file['name']));
		}else{
			$error = true;
		}
	}
	$response = $app->response();
	$response->body(json_encode($data));
});

$app->post("/deleteChatMessages", function() use ($app, $mongo) {
  $request = $app->request();
  $data = json_decode($request->getBody());
  $db = $mongo->CodeEditor;
  $chatCollection = $db->Chat;
  $chatCollection->update(
    array("fileId" => $data->fileid),
		array('$pull' => array(
			"history" => array(
				"chatid" => new MongoId($data->chatid)
			)
		))
  );
});

$app->post("/insertChatMessages", function() use ($app, $mongo) {
	$request = $app->request();
	$data = json_decode($request->getBody());
	$db = $mongo->CodeEditor;
	$chatCollection = $db->Chat;
  $newid = new MongoId();
	$chatCollection->update(
		array("fileId" => $data->fileid),
		array('$push' => array(
			"history" => array(
        "chatid" => $newid,
        "senderid" => $_SESSION["userId"],
				"sender" => $data->sender,
				"date" => $data->date,
				"message" => $data->message
			)
		)),
		array("upsert" => true)
	);

  $sendData = array("chatid" => $newid->{'$id'}, "senderid" => $_SESSION['userId']);
	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode(array("status" => "success", "res" => $sendData)));
});

$app->get("/loadChatMessages/:fileid", function($fileid) use ($app, $mongo) {
	$db = $mongo->CodeEditor;
	$chatCollection = $db->Chat;

	$data = array();
	$res = $chatCollection->find(array("fileId" => $fileid));
	foreach ($res as $doc) {
		for ($i = 0; $i < count($doc["history"]); ++$i)
			array_push($data, array(
        "chatid" => $doc["history"][$i]["chatid"]->{'$id'},
        "senderid" => $doc["history"][$i]["senderid"],
				"sender" => $doc["history"][$i]["sender"],
				"date" => $doc["history"][$i]["date"],
				"message" => $doc["history"][$i]["message"]
			));
	}
	$response = $app->response();
	$response['Content-Type'] = 'application/json';
	$response->body(json_encode($data));
});

$app->post("/checkCaptcha", function() use ($app) {
	$request = $app->request();
	$data = json_decode($request->getBody());

	if(rpHash($data->realPerson) == $data->realPersonHash)
		echo "success";
	else
		echo "error";
});

$app->post("/sendEmail", function() use ($app) {
	$request = $app->request();
	$data = json_decode($request->getBody());
	$mail = new PHPMailer;

	$mail->IsSMTP();
	$mail->Host = 'smtp.gmail.com';
	$mail->SMTPAuth = true;
	$mail->Username = 'codeeditor14@gmail.com';
	$mail->Password = 'codeeditor2014';
	$mail->SMTPSecure = 'tls';
	$mail->Port = 587;
	$mail->SMTPDebug = 1;


	$mail->From = $data->name;
	$mail->FromName = $data->name;
	$mail->AddAddress('codeeditor14@gmail.com');

	$mail->WordWrap = 50;
	$mail->IsHTML(true);

	$mail->Subject = $data->subject;
	$mail->Body    = $data->message.
		'<br/><br/><br/>
		<p style="color:gray">Please reply to :'.$data->email.'<br/>
		Thank you</p>';
	$mail->IsHTML(true);

	if(!$mail->Send()) {
	   echo 'Message could not be sent.';
	   echo 'Mailer Error: ' . $mail->ErrorInfo;
	}

	echo 'Message has been sent';

});

$app->run();

function rpHash($value) {
	$hash = 5381;
	$value = strtoupper($value);
	for($i = 0; $i < strlen($value); $i++) {
		$hash = (($hash << 5) + $hash) + ord(substr($value, $i));
	}
	return $hash;
}

?>
