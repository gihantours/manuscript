<?php

namespace App\Http\Requests;

use App\Services\ManuscriptFileValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreManuscriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'author_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'priority' => ['required', 'string', Rule::in(['normal', 'high'])],
            'file' => [
                'required',
                'file',
                'max:10240',
                'extensions:txt',
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $file = $this->file('file');
                $path = $file?->getRealPath();
                $contents = $path !== false && $path !== null ? file_get_contents($path) : false;

                if ($contents === false) {
                    $validator->errors()->add('file', 'The uploaded file could not be read.');

                    return;
                }

                try {
                    app(ManuscriptFileValidator::class)->validate($contents, $path ?: null);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add('file', $message);
                        }
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The manuscript must be 10 MB or smaller.',
            'file.extensions' => 'The manuscript must be a .txt file.',
        ];
    }
}
