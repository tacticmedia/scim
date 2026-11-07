<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\EndToEnd;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TacticMedia\ScimDemo\ServiceProvider\Entity\Group;
use TacticMedia\ScimDemo\ServiceProvider\Entity\User;
use TacticMedia\ScimDemo\ServiceProvider\Kernel;

/**
 * The demo SP as a php -S process on a free port with its own SQLite file, and its kernel in this process against
 * the same file.
 *
 * @phpstan-type UserState array<string, mixed>
 * @phpstan-type State array{users: array<string, UserState>, groups: array<string, list<string>>}
 */
final class ServiceProviderServer
{
    private const string FRONT_CONTROLLER_DIRECTORY = __DIR__.'/public';
    private const int START_TIMEOUT_SECONDS = 10;
    private const string ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    public private(set) string $scimBaseUrl = '';

    private readonly string $databasePath;

    private readonly HttpClientInterface $scim;

    private ?Process $process = null;

    private ?Kernel $kernel = null;

    public function __construct(private readonly string $token)
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'scim-e2e-') ?: throw new \RuntimeException('Cannot create a temporary file.');
        $this->scim = HttpClient::create(['headers' => ['Authorization' => 'Bearer '.$token], 'timeout' => self::START_TIMEOUT_SECONDS]);
    }

    /**
     * Creates the schema with the SP kernel of this process, starts the server and waits until it answers.
     */
    public function start(): void
    {
        $_SERVER['DATABASE_PATH'] = $this->databasePath;
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        $entityManager = $this->entityManager();
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Nothing reads the output pipes while the IdP waits for the SP. "-q" and no SHELL_VERBOSITY keep a full pipe
        // from blocking the SP.
        $environment = ['DATABASE_PATH' => $this->databasePath, 'SCIM_TOKEN' => $this->token, 'SHELL_VERBOSITY' => false, 'XDEBUG_MODE' => 'off'];
        $address = '127.0.0.1:'.$this->freePort();
        $this->scimBaseUrl = 'http://'.$address.'/scim/v2';
        $this->process = new Process([\PHP_BINARY, '-S', $address, '-q', '-t', self::FRONT_CONTROLLER_DIRECTORY], env: $environment, timeout: null);
        $this->process->start();
        $this->awaitAnswer($this->process);
    }

    public function stop(): void
    {
        $this->process?->stop(1);
        $this->kernel?->shutdown();
        unset($_SERVER['DATABASE_PATH']);

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    /**
     * Without soft-deleted users, as the directory page shows them.
     *
     * @return State
     */
    public function databaseState(): array
    {
        $entityManager = $this->entityManager();
        $users = [];

        foreach ($entityManager->getRepository(User::class)->findBy(['deletedAt' => null]) as $user) {
            $users[$user->getUserName()] = [
                'displayName' => $user->getDisplayName(),
                'name' => [$user->getGivenName(), $user->getFamilyName()],
                'email' => $user->getEmail(),
                'department' => $user->getDepartment(),
                'employeeNumber' => $user->getEmployeeNumber(),
                'manager' => true === $user->getManager()?->isDeleted() ? null : $user->getManager()?->getUserName(),
                'active' => $user->isActive(),
            ];
        }

        $groups = [];

        foreach ($entityManager->getRepository(Group::class)->findAll() as $group) {
            $groups[$group->getDisplayName()] = $group->getUsers()->filter(static fn (User $member): bool => !$member->isDeleted())->map(static fn (User $member): string => $member->getUserName())->getValues();
        }

        return self::state($users, $groups);
    }

    /**
     * @return State
     */
    public function scimState(): array
    {
        $resources = $this->resources('/Users');
        $userNames = array_column($resources, 'userName', 'id');
        $users = [];

        foreach ($resources as $user) {
            $enterprise = $user[self::ENTERPRISE_USER] ?? [];
            $users[$user['userName']] = [
                'displayName' => $user['displayName'] ?? null,
                'name' => [$user['name']['givenName'] ?? null, $user['name']['familyName'] ?? null],
                'email' => $user['emails'][0]['value'] ?? null,
                'department' => $enterprise['department'] ?? null,
                'employeeNumber' => $enterprise['employeeNumber'] ?? null,
                'manager' => $userNames[$enterprise['manager']['value'] ?? ''] ?? null,
                'active' => $user['active'] ?? null,
            ];
        }

        $groups = [];

        foreach ($this->resources('/Groups') as $group) {
            $groups[$group['displayName']] = array_values(array_map(static fn (array $member): string => $userNames[$member['value']] ?? $member['value'], $group['members'] ?? []));
        }

        return self::state($users, $groups);
    }

    /**
     * @param array<string, UserState>    $users
     * @param array<string, list<string>> $groups
     *
     * @return State
     */
    public static function state(array $users, array $groups): array
    {
        ksort($users);
        ksort($groups);

        foreach ($groups as &$members) {
            sort($members);
        }

        return ['users' => $users, 'groups' => $groups];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new \RuntimeException('Cannot open a socket.');
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) parse_url('tcp://'.$address, \PHP_URL_PORT);
    }

    private function awaitAnswer(Process $process): void
    {
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;

        while (!$this->answers()) {
            if (!$process->isRunning() || microtime(true) > $deadline) {
                $process->stop(0);
                Assert::fail(\sprintf("The service provider at %s stopped or did not answer within %d seconds. Exit code: %s. Output:\n%s", $this->scimBaseUrl, self::START_TIMEOUT_SECONDS, $process->getExitCode() ?? 'none', $process->getOutput().$process->getErrorOutput()));
            }

            usleep(20_000);
        }
    }

    private function answers(): bool
    {
        try {
            $this->scim->request('GET', $this->scimBaseUrl.'/ServiceProviderConfig')->getStatusCode();

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    /**
     * @return list<array<mixed>>
     */
    private function resources(string $endpoint): array
    {
        return $this->scim->request('GET', $this->scimBaseUrl.$endpoint)->toArray()['Resources'] ?? [];
    }

    private function entityManager(): EntityManagerInterface
    {
        $doctrine = $this->kernel?->getContainer()->get('doctrine');
        Assert::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        Assert::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        return $entityManager;
    }
}
