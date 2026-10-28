<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Ticketing/TicketManualReview.php';

function ticket_review_parse(array $arguments): array
{
    $command = array_shift($arguments) ?? '';
    if (!in_array($command, ['classify-individual', 'approve', 'reject'], true)) {
        throw new InvalidArgumentException(
            'Usage: php bin/ticket_review.php classify-individual|approve|reject --crm-id=CRM-... --reviewer=USERNAME [--queue-rpa] [--reason=TEXT]'
        );
    }
    $options = ['queue-rpa' => false];
    foreach ($arguments as $argument) {
        if ($argument === '--queue-rpa' && !$options['queue-rpa']) {
            $options['queue-rpa'] = true;
            continue;
        }
        if (!preg_match('/^--(crm-id|reviewer|reason)=(.+)$/', $argument, $matches)
            || isset($options[$matches[1]])) {
            throw new InvalidArgumentException('Invalid or duplicate ticket review option.');
        }
        $options[$matches[1]] = $matches[2];
    }
    if (empty($options['crm-id']) || empty($options['reviewer'])) {
        throw new InvalidArgumentException('--crm-id and --reviewer are required.');
    }
    if ($command === 'reject' && empty($options['reason'])) {
        throw new InvalidArgumentException('A non-empty --reason is required when rejecting.');
    }
    if ($command !== 'approve' && $options['queue-rpa']) {
        throw new InvalidArgumentException('--queue-rpa is valid only with approve.');
    }
    return [$command, $options];
}

try {
    [$command, $options] = ticket_review_parse(array_slice($argv, 1));
    $review = new \Cidb\Ticketing\TicketManualReview(db());
    $result = match ($command) {
        'classify-individual' => $review->classifyIndividual($options['crm-id'], $options['reviewer']),
        'approve' => $review->approve($options['crm-id'], $options['reviewer'], $options['queue-rpa']),
        'reject' => $review->reject($options['crm-id'], $options['reviewer'], $options['reason']),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Manual ticket review failed (' . get_class($error) . '): ' . $error->getMessage() . "\n");
    exit(1);
}
