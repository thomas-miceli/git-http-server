<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class GitHTTPServer
 */
class GitHTTPServer
{
    /**
     * @var false|string Absolute path to the folder containing all the remote repositories
     */
    private string $repositoryRoot;

    /**
     * @var array Users who can clone/fetch/pull the remote repository if the repository is private
     *
     * The structure of the array must be like this
     * $whoCanRead = [
     *     'user1' => 'password1',
     *     'user2' => 'password2',
     *      // etc ...
     * ]
     */
    private array $whoCanRead;

    /**
     * @var array Users who can push to the remote repository
     *
     * The structure of the array must be like this
     * $whoCanWrite = [
     *     'user1' => 'password1',
     *     'user2' => 'password2',
     *      // etc ...
     * ]
     */
    private array $whoCanWrite;

    /**
     * @var bool Whether the remote repository can be accessed by everyone or only by users contained by $whoCanRead
     */
    private bool $isPrivate;

    /**
     * @var Request Symfony HTTPFoundation Request object
     */
    private Request $request;

    /**
     * @var Response Symfony HTTPFoundation Response object
     */
    private Response $response;

    const RECEIVE = 'git-receive-pack';

    const UPLOAD = 'git-upload-pack';

    /**
     * GitHTTPServer constructor.
     *
     * @param string $repositoryRoot Path to the folder containing all the remote repositories
     * @param array  $whoCanRead     Users who can clone/fetch/pull the remote repository
     * @param array  $whoCanWrite    Users who can push to the remote repository
     * @param bool   $isPrivate      If the remote repository is private or not
     */
    public function __construct(
        string $repositoryRoot,
        array $whoCanRead = [],
        array $whoCanWrite = [],
        bool $isPrivate = false
    ) {
        $this->repositoryRoot = realpath($repositoryRoot);
        $this->whoCanRead = $whoCanRead;
        $this->whoCanWrite = $whoCanWrite;
        $this->isPrivate = $isPrivate;
        $this->request = Request::createFromGlobals();
        $this->response = Response::create();
    }

    /**
     * Call in a new process the git http-backend executable with the environment variables,
     * then process the desired operations defined by the request headers and finally set
     * the output on the @Response element.
     */
    private function process()
    {
        $git_env = [
            "GIT_HTTP_EXPORT_ALL" => "",
            "GIT_PROJECT_ROOT" => $this->repositoryRoot,
            "PATH_INFO" => $this->request->getPathInfo(),
            "CONTENT_TYPE" => $this->request->headers->get('CONTENT_TYPE'),
        ];

        $env = array_merge($this->request->server->all(), $git_env);

        $process = proc_open(
            'git http-backend', [
            ["pipe", "r"],
            ["pipe", "w"],
            ], $pipes, null, $env
        );

        if (!is_resource($process)) {
            $this->response->setStatusCode(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Could not create process.'
            );
            return;
        }

        if ($this->request->getMethod() == "POST") {
            fwrite($pipes[0], file_get_contents("php://input"));
            fclose($pipes[0]);
        }

        $content = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        if (proc_close($process)) {
            $err = stream_get_contents($pipes[2]);
            $this->response->setStatusCode(Response::HTTP_NOT_EXTENDED, $err);
            return;
        }

        list($header, $body) = explode("\r\n\r\n", $content);
        $headers = explode("\n", $header);

        foreach ($headers as $header) {
            $key = strtok($header, ':');
            $value = strtok(null);
            $this->response->headers->set($key, $value);
        }
        $this->response->setContent($body);
    }

    /**
     * Run the wrapper by retrieving request parameters and headers and checking for authentication.
     *
     * If the repository is 'public' :
     *      * everybody    can fetch the remote repository
     *      * @see $whoCanWrite can push to the repo remote repository
     *
     * If the repository is 'private' :
     *      * @see $whoCanRead  can fetch the remote repository
     *      * @see $whoCanWrite can push to the repo remote repository
     */
    public function run()
    {
        $user = $this->request->headers->get('PHP_AUTH_USER');
        $password = $this->request->headers->get('PHP_AUTH_PW');
        $service = $this->request->get('service');

        if (($this->isPrivate == true && $service == self::UPLOAD && !$this->canUserRead($user, $password))
            || ($service == self::RECEIVE && !$this->canUserWrite($user, $password))
        ) {
            $this->response->setStatusCode(Response::HTTP_UNAUTHORIZED);
            $this->response->headers->set('WWW-Authenticate', 'Basic realm="git"');
            return;
        }

        $this->process();
    }

    /**
     * Checks if the user entered his credentials and verify if he can read the remote repository.
     *
     * @param  string|null $user
     * @param  string|null $password
     * @return bool
     */
    private function canUserRead(?string $user, ?string $password): bool
    {
        if ($user == null || $password == null) {
            return false;
        }

        foreach ($this->whoCanRead as $key => $value) {
            if ($key == $user && $value == $password) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the user entered his credentials and verify if he can write to the remote repository.
     *
     * @param  string|null $user
     * @param  string|null $password
     * @return bool
     */
    private function canUserWrite(?string $user, ?string $password): bool
    {
        if ($user == null || $password == null) {
            return false;
        }

        foreach ($this->whoCanWrite as $key => $value) {
            if ($key == $user && $value == $password) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the Symfony HTTPFoundation Response.
     *
     * @return Response
     */
    public function getResponse() : Response
    {
        return $this->response;
    }
}
