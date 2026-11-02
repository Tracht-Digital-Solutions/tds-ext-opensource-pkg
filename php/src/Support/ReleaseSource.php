<?php
declare(strict_types=1);

namespace Tds\Ext\Opensource\Support;

/** Where a project's public releases come from. An interface so tests need no network. */
interface ReleaseSource
{
    /**
     * Public releases, newest first.
     *
     * @return list<array{version:string,date:?string,url:?string,body:?string,prerelease:bool}>
     * @throws \RuntimeException with a German sentence for the panel
     */
    public function releases(string $repoUrl): array;
}
