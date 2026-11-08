<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the patient did not say, as the AI summary reports it. A fixed set, never free text: the model can only pick
 * from these keys, so a gap can't carry a diagnosis, advice or a made-up question. Each key means "the answers do not
 * mention this at all". The doctor page shows the label, not the key.
 */
enum SummaryGap: string
{
    case Onset = 'onset';
    case Severity = 'severity';
    case Location = 'location';
    case Triggers = 'triggers';
    case EarlierEpisodes = 'earlier_episodes';
    case AlreadyTried = 'already_tried';

    public function label(): string
    {
        return match ($this) {
            self::Onset => 'When it started',
            self::Severity => 'How bad it is',
            self::Location => 'Where it is',
            self::Triggers => 'What makes it better or worse',
            self::EarlierEpisodes => 'Earlier episodes',
            self::AlreadyTried => 'What was already tried',
        };
    }

    /** What the model is told each key means, so it does not guess. Used in the agent's instructions. */
    public function meaning(): string
    {
        return match ($this) {
            self::Onset => 'when the problem started',
            self::Severity => 'how severe it is',
            self::Location => 'where on the body it is',
            self::Triggers => 'what makes it better or worse',
            self::EarlierEpisodes => 'whether it has happened before',
            self::AlreadyTried => 'medicines or home remedies already used',
        };
    }
}
