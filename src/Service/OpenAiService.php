<?php

declare(strict_types=1);

namespace DigitalExchange\AiMailReply\Service;

use DigitalExchange\AiMailReply\Config\AppConfig;
use DigitalExchange\AiMailReply\Domain\Email;
use DigitalExchange\AiMailReply\Domain\AiResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class OpenAiService
{
    private AppConfig $config;
    private Logger $logger;
    private Client $client;
    private string $defaultPrompt;

    public function __construct(AppConfig $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $config->getOpenaiConfig()['api_key'],
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->defaultPrompt = $config->getDefaultPrompt();
    }

    /**
     * Generate AI response for email
     * 
     * @param Email $email Email object
     * @return AiResponse AI response
     * @throws \Exception If API request fails
     */
    public function generateResponse(Email $email): AiResponse
    {
        try {
            // Prepare prompt
            $prompt = $this->preparePrompt($email);
            
            // Prepare messages for API
            $messages = [
                [
                    'role' => 'system',
                    'content' => $this->defaultPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ];

            // Add instruction for response format
            $messages[] = [
                'role' => 'system',
                'content' => "Asla Emoji ve Markdown kullanmadan yanıt verme! Tüm cevaplarında Markdown formatını kullanmalısın ve cevabında emojiler kullanmalısın.
Önemli kelime öbeklerinin altını çizmeli (__altı çizili__), önemli yerleri bold yapmalı (**kalın**), kısa alıntıları yatık yapmalı (*italik*) gibi biçimleri uygulamalısın.
Markdown formatını kullanmalısın. Emojiler için Unicode UTF-8 kullanmalısın. Her başlıkta en az bir emoji kullanmalısın. Örneğin: 👍 🎉 ✅ 😊 👋 🚀 ⚠️ ❗ ❓ ✨ 💡 gibi. Emojiler e-posta içeriğinde görünecek ve mesajı daha canlı hale getirecektir."
            ];

            if ($this->config->allowAiRecipients()) {
                // Add instruction for response format
                $messages[] = [
                    'role' => 'system',
                    'content' => "Yanıtını oluştururken, özel talimatlar için JSON formatını kullanabilirsin. Eğer sana e-posta gönderen kişi e-postayı sadece kime göndermen ya da bu e-posta gönderimine eklemen kişileri açık bir şekilde belirttiyse bunları, aşağıdaki gibi bir JSON bloğunda planlayabilirsin:

                    ```json
                    {
                    \"recipients\": [\"ornek@example.com\"],
                    \"cc\": [\"kopya@example.com\"],
                    \"only_to_these_recipients\": true
                    }
                    ```

                    Bu JSON bloğu, yanıtının sonunda yer almalıdır ve normal yanıt metninden ayrı olmalıdır. JSON bloğu olmadan da yanıt verebilirsin, bu durumda varsayılan olarak tüm alıcılara yanıt gönderilecektir.
                    Sana e-posta gönderen bu e-postayı sadece belli kişilere göndermeni ya da belli kişileri eklemen gerektiğini belirtmediyse kesinlikle eposta akışında olan e-postaları toplayıp cevap verme!
                    Hayali e-postalar uydurma. Burada insanlar tarafından hatalı to ve cc'ler yazılabileceği için sana kesin olarak verilen direktiflerin dışına çıkmamalısın."
                ];
            }

            // Make API request
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $this->config->getOpenaiConfig()['model'],
                    'messages' => $messages,
                    'temperature' => 0.5,
                    'max_tokens' => 10000,
                ]
            ]);

            // Parse response
            $responseData = json_decode((string) $response->getBody(), true);

            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid API response format');
            }

            $aiResponseText = $responseData['choices'][0]['message']['content'];

            // Create AiResponse object
            $aiResponse = AiResponse::fromAiResponseText($aiResponseText);

            $this->logger->info('AI response generated successfully. Response content:', [
                'email_subject' => $email->getSubject(),
                'response_length' => strlen($aiResponseText),
                'response_content' => $aiResponseText,
                'processed_content' => $aiResponse->getContent()
            ]);
            
            return $aiResponse;
        } catch (GuzzleException $e) {
            $this->logger->error('OpenAI API request failed: ' . $e->getMessage());
            throw new \Exception('OpenAI API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Prepare prompt for OpenAI API
     * 
     * @param Email $email Email object
     * @return string Prepared prompt
     */
    private function preparePrompt(Email $email): string
    {
        $prompt = '';
        
        // Add custom prompt if available
        if ($email->getCustomPrompt() && strlen($email->getCustomPrompt()) === 10) {
            $prompt .= "Özel Yönerge: " . $email->getCustomPrompt() . "\n\n";
        }
        
        // Add current email
        $prompt .= "Son E-posta:\n";
        $prompt .= "Kimden: " . $email->getFromName() . " <" . $email->getFrom() . ">\n";
        $prompt .= "Konu: " . $email->getSubject() . "\n";
        
        // Temizlenmiş ve düzgün formatlanmış içerik
        $cleanBody = $this->cleanEmailContent($email->getBody());
        $prompt .= "İçerik:\n" . $cleanBody . "\n\n";
        
        // Add thread emails if available
        if (!empty($email->getThreadEmails())) {
            $prompt .= "Önceki E-postalar:\n";
            
            foreach (array_reverse($email->getThreadEmails()) as $index => $threadEmail) {
                // Temizlenmiş ve düzgün formatlanmış içerik
                $cleanThreadEmail = $this->cleanEmailContent($threadEmail);
                
                $prompt .= "--- E-posta " . ($index + 1) . " ---\n";
                $prompt .= $cleanThreadEmail . "\n\n";
            }
        }
        
        $this->logger->info('Prepare prompt', [
            'prompt' => $prompt
        ]);

        return $prompt;
    }
    
    /**
     * Clean email content for better readability
     * 
     * @param string $content Email content
     * @return string Cleaned content
     */
    private function cleanEmailContent(string $content): string
    {
        // HTML etiketlerini kaldır
        $content = strip_tags($content);
        
        // Quoted-printable kodlamasını çöz (eğer hala varsa)
        $content = quoted_printable_decode($content);
        
        // Gereksiz boşlukları temizle
        $content = preg_replace('/\s+/', ' ', $content);
        
        // E-posta başlık bilgilerini temizle
        $content = preg_replace('/^>.*$/m', '', $content);
        
        // Satır sonlarını düzelt
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        // Birden fazla boş satırı tek satıra indir
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Özel karakterleri düzelt
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        
        // =?UTF-8?B? gibi kodlanmış başlıkları çöz
        $content = preg_replace_callback(
            '/=\?UTF-8\?B\?(.*?)\?=/',
            function ($matches) {
                return base64_decode($matches[1]);
            },
            $content
        );
        
        // =?UTF-8?Q? gibi kodlanmış başlıkları çöz
        $content = preg_replace_callback(
            '/=\?UTF-8\?Q\?(.*?)\?=/',
            function ($matches) {
                return quoted_printable_decode(str_replace('_', ' ', $matches[1]));
            },
            $content
        );
        
        // =C4=9F gibi kodlanmış karakterleri çöz
        $content = preg_replace_callback(
            '/=([0-9A-F]{2})/',
            function ($matches) {
                return chr(hexdec($matches[1]));
            },
            $content
        );
        
        return trim($content);
    }
}