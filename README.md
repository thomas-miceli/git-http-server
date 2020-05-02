# git-http-server

Tiny git http server wrapper written in PHP.

Tested with `git 2.26.2`.
### Installation 

```
composer require thomas-miceli/git-http-server
```

### Usage

```php

$whoCanRead = [
    'user1' => 'password1',
    'user2' => 'password2',
     // etc ...
];

$whoCanWrite = [
    'user1' => 'password1',
    'user2' => 'password2',
     // etc ...
];

$isPrivate = true;

$gitServer = new GitHTTPServer('/path/to/repos', $whoCanRead, $whoCanWrite, $isPrivate);
$gitServer->run()
return $gitServer->getResponse()->send();
```

The code above must be executed when the following routes are requested by the client 
* `https://my.gitserver.org/my-repository.git/info/refs`
* `https://my.gitserver.org/my-repository.git/git-receive-pack`
* `https://my.gitserver.org/my-repository.git/git-upload-pack`

