<?php

namespace facilo\portero\Craft;

use Craft;
use facilo\portero\Core\Connection;
use facilo\portero\Core\Decision;
use facilo\portero\Core\Reason;
use facilo\portero\Core\Result;

/** Turns a test result into what the result page shows. Nothing here is written anywhere. */
class TestReport
{
    public function build(Connection $connection, Result $result): array
    {
        $provider = $connection->providerLabel();
        $report = [
            'passed' => $result->passed(),
            'provider' => $provider,
            'email' => $result->identity?->email,
            'rows' => $this->rows($connection, $result),
        ];
        if ($result->passed()) {
            $decision = $result->decision;
            $report['what'] = $decision->action === Decision::CREATE
                ? Craft::t('portero-sso', 'Create a new account')
                : Craft::t('portero-sso', 'Sign in as {name}', ['name' => $decision->user->name]);
            $report['add'] = $decision->addGroups;
            $report['remove'] = $decision->removeGroups;
            return $report;
        }
        $code = $result->refused->reason;
        $params = ['provider' => $provider, 'status' => $result->refused->detail, 'detail' => $result->refused->detail];
        $report['code'] = $code;
        $report['explanation'] = Craft::t('portero-sso', Reason::explain($code), $params);
        $report['fix'] = Craft::t('portero-sso', Reason::fix($code), $params);
        return $report;
    }

    private function rows(Connection $connection, Result $result): array
    {
        $labels = [];
        $map = $connection->attributeMap;
        foreach (['email' => 'Email', 'firstName' => 'First name', 'lastName' => 'Last name', 'groups' => 'Group rules'] as $key => $label) {
            if (isset($map[$key])) {
                $labels[$map[$key]] = Craft::t('portero-sso', $label);
            }
        }
        $rows = [];
        foreach ($result->identity->attributes ?? [] as $name => $values) {
            $rows[] = ['name' => $name, 'values' => implode(', ', $values), 'used' => $labels[$name] ?? '–'];
        }
        return $rows;
    }
}
