<?php

namespace App\Http\Requests;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\Contracts\Importer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Enum;

class RunImportRequest extends FormRequest
{
    /**
     * Two gates, both of which have to open. Holding the import permission
     * is not enough on its own: a sheet answers to the same permission that
     * guards editing those records by hand, so an import can never be a way
     * into a page somebody cannot reach.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->hasPermission(Permission::ImportData)) {
            return false;
        }

        /** @var Importer $importer */
        $importer = $this->route('importer');

        return $user->hasPermission($importer->permission());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Spreadsheets are saved with all sorts of MIME types depending
            // on what wrote them, so the extension is what is checked and the
            // reader decides whether the contents make sense.
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,text/tsv,application/csv,application/vnd.ms-excel', 'extensions:csv,txt', 'max:10240'],
            'duplicates' => ['required', new Enum(ImportDuplicates::class)],
            // The default. Committing has to be asked for, so a stray request
            // checks the file rather than writes it.
            'commit' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the file to import.',
            'file.mimetypes' => 'That is not a CSV. In Excel, use File, Save As, and pick CSV UTF-8.',
            'file.extensions' => 'That is not a CSV. In Excel, use File, Save As, and pick CSV UTF-8.',
            'file.max' => 'That file is larger than 10MB. Split it and import the parts one after another.',
        ];
    }

    public function csv(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    public function duplicates(): ImportDuplicates
    {
        return $this->enum('duplicates', ImportDuplicates::class) ?? ImportDuplicates::Update;
    }

    public function commits(): bool
    {
        return $this->boolean('commit');
    }
}
