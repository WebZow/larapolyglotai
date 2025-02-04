<?php

namespace WebZOW\Larapolyglotai\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\text;

class LarapolyglotaiCommand extends Command
{
    //protected string $originFolder = 'pt_BR';

    protected $signature = 'app:new-lang {--name= : Language code (e.g., es, fr, de)} {--country= : Country name (e.g., Spain, France)}';

    protected $description = 'Creates a new language folder by copying files from pt_BR and auto-translating them.';

    private $httpClient;
    private ?string $openaiKey;

    public function __construct(Client $client)
    {
        parent::__construct();
        $this->httpClient = $client;
    }

    public function handle(): int
    {
//        $langCode = $this->option('name');
//        $country = $this->option('country');
//
//        if (! $langCode || ! $country) {
//            $this->error('Please provide both --name=xx and --country=CountryName.');
//            return 1;
//        }

        if ($this->openaiKey = config('larapolyglotai.openaikey') === null) {
            $this->warn('Configure your OpenAI API key in the config file.');

            $this->openaiKey = text(
                label: 'OpenAI API key',
                placeholder: 'E.g. sk-1234567890abcdef......',
                hint: 'Your OpenAI API key.',
            );
        }

        $langCode = text(
            label: 'Language code',
            placeholder: 'E.g. es, fr, de, ru, pt_BR',
            hint: 'The language code for the new language (e.g., es, fr, de, ru, pt_BR)',
        );

        $country = text(
            label: 'Country name',
            placeholder: 'E.g. Spain, France, Germany, Russia, Brazil',
            hint: 'The country name for the new language (e.g., Spain, France, Germany, Russia, Brazil)',
        );

        $sourcePath = lang_path( config('larapolyglotai.origin_path') );
        $destinationPath = lang_path($langCode);

        if (! is_dir($sourcePath)) {
            $this->error("Source language folder ($sourcePath) does not exist.");

            return 1;
        }

        if (! is_dir($destinationPath)) {
            $filesystem = new Filesystem;
            $filesystem->copyDirectory($sourcePath, $destinationPath);
            $this->info('Language folder has created successfully.');
        }

        $this->info("Starting translation for '$langCode' ($country)...");

        $this->translateFiles($sourcePath, $destinationPath, $langCode, $country);

        $this->newLine(2);
        $this->info("Translation completed for '$langCode' ($country).");

        return 0;
    }

    private function translateFiles($sourcePath, $destinationPath, $langCode, $country): void
    {
        $files = glob("$sourcePath/*.php");

        foreach ($files as $file) {
            $filename = basename($file);
            $sourceContent = file_get_contents($file);
            $destinationFile = "$destinationPath/$filename";

            if (! file_exists($destinationFile)) {
                file_put_contents($destinationFile, $sourceContent);
            }

            $destinationContent = file_get_contents($destinationFile);

            $translatedContent = $this->translateDifferences($sourceContent, $destinationContent, $langCode, $country);

            if ($translatedContent) {
                file_put_contents($destinationFile, $translatedContent);
                // $this->info("Translated: $filename"); // Translated: auth.php
            } else {
                $this->info("No changes needed for: $filename");
            }
        }
    }

    private function translateDifferences($sourceContent, $destinationContent, $langCode, $country): ?string
    {
        $sourceArray = $this->extractPhpArray($sourceContent);

        if (! $sourceArray) {
            $this->error('Failed to parse source file.');

            return null;
        }

        $translatedArray = $this->translateWholeArray($sourceArray, $langCode, $country);

        if ($translatedArray === null) {
            return null;
        }

        return $this->generatePhpArrayString($translatedArray);
    }

    private function extractPhpArray($fileContent): ?array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'lang');
        file_put_contents($tempFile, $fileContent);
        $array = include $tempFile;
        unlink($tempFile);

        return is_array($array) ? $array : null;
    }

    private function generatePhpArrayString($array)
    {
        $arrayString = "<?php\n\nreturn [\n";

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $arrayString .= "    '$key' => ".$this->generateNestedArrayString($value, 1).",\n";
            } else {
                $value = str_replace("'", "\\'", $value);
                $arrayString .= "    '$key' => '".$value."',\n";
            }
        }

        $arrayString .= "];\n";

        return $arrayString;
    }

    private function generateNestedArrayString($array, $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $arrayString = "[\n";

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $arrayString .= $indent."    '$key' => ".$this->generateNestedArrayString($value, $depth + 1).",\n";
            } else {
                $value = str_replace("'", "\\'", $value);
                $arrayString .= $indent."    '$key' => '".$value."',\n";
            }
        }

        $arrayString .= $indent.']';

        return $arrayString;
    }

    private function translateWholeArray($sourceArray, $langCode, $country)
    {
        $flattenedArray = $this->flattenArray($sourceArray);
        $translatedFlatArray = $this->translateArrayChunks($flattenedArray, $langCode, $country);

        return $this->unflattenArray($translatedFlatArray, $sourceArray);
    }

    private function flattenArray($array, $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? $prefix.'.'.$key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    private function unflattenArray($flatArray, $originalStructure)
    {
        $result = $originalStructure;

        foreach ($flatArray as $key => $value) {
            $keys = explode('.', $key);
            $current = &$result;

            foreach ($keys as $k) {
                $current = &$current[$k];
            }

            $current = $value;
        }

        return $result;
    }

    private function translateArrayChunks($array, $langCode, $country)
    {
        $translatedArray = [];
        $chunkSize = 500;
        $chunks = array_chunk($array, $chunkSize, true);

        foreach ($chunks as $index => $chunk) {
            $chunkText = json_encode($chunk, JSON_UNESCAPED_UNICODE);
            $translatedText = $this->translateText($chunkText, $langCode, $country);

            if ($translatedText) {
                // Remove markdown code block markers
                $translatedText = preg_replace('/^```json\n|\n```$/', '', $translatedText);

                $translatedChunk = @json_decode($translatedText, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($translatedChunk)) {
                    $translatedArray = array_merge($translatedArray, $translatedChunk);
                } else {
                    $this->error('Invalid translation chunk #'.($index + 1).': '.$translatedText);
                    $translatedArray = array_merge($translatedArray, $chunk);
                }
            } else {
                $translatedArray = array_merge($translatedArray, $chunk);
                $this->error('Translation failed for chunk #'.($index + 1));
            }
        }

        return $translatedArray;
    }

    private function translateText($text, $langCode, $country)
    {
        try {
            $response = $this->httpClient->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '. $this->openaiKey,
                ],
                'json' => [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a professional translator. Translate the following content php from {config('larapolyglotai.origin_path')} to {$country} language ({$langCode}).",
                        ],
                        [
                            'role' => 'user',
                            'content' => $text,
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 3000,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            return $body['choices'][0]['message']['content'] ?? null;
        } catch (RequestException $e) {
            $this->error('Translation request failed: '.$e->getMessage());

            return null;
        }
    }
}
