<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Data\AccountLogosData;
use App\Domain\Accounts\Enums\AccountLogo;
use App\Domain\Accounts\Models\Account;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Stores, replaces and removes an account's logos.
 *
 * No `asController()`: a logo is part of the account's registration, so it is
 * saved by CreateAccount and UpdateAccount, together with the rest of the form.
 *
 * Every upload gets a fresh name inside the account's folder, never the name
 * the file arrived with. That is what changes the URL when the image changes,
 * and so what lets the browser keep the old one cached for good.
 *
 * The order is what makes a failure harmless. The new file is written first,
 * the row is pointed at it second, and the file it replaced is deleted only
 * after the surrounding transaction commits: a rollback at any point leaves
 * the row pointing at a file that still exists. The worst case is an orphaned
 * upload in the account's folder, never a broken logo.
 */
final class SaveAccountLogos
{
    use AsAction;

    public function handle(Account $account, AccountLogosData $data): Account
    {
        $columns = [];

        foreach ($data->changes as [$logo, $file]) {
            $columns[$logo->column()] = $file === null ? null : $this->store($account, $logo, $file);
        }

        if ($columns === []) {
            return $account;
        }

        $replaced = array_values(array_filter(Arr::only($account->getAttributes(), array_keys($columns))));

        $account->update($columns);

        DB::afterCommit(static fn () => Storage::disk(Account::FILES_DISK)->delete($replaced));

        return $account;
    }

    private function store(Account $account, AccountLogo $logo, UploadedFile $file): string
    {
        // The extension comes from the content, not from the name the browser
        // sent, so a PNG renamed to .jpg is stored as what it is.
        $name = sprintf('logo-%s-%s.%s', $logo->value, Str::lower(Str::random(12)), $file->extension());

        $path = $file->storeAs($account->storageDirectory(), $name, Account::FILES_DISK);

        if ($path === false) {
            throw new RuntimeException("Não foi possível gravar a {$logo->attribute()} da conta {$account->id}.");
        }

        return $path;
    }
}
