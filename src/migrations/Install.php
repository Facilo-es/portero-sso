<?php

namespace facilo\portero\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%portero_identities}}', [
            'id' => $this->primaryKey(),
            'connectionUid' => $this->char(36)->notNull(),
            'subject' => $this->string(512)->notNull(),
            'subjectHash' => $this->char(64)->notNull(),
            'userId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'lastSignIn' => $this->dateTime(),
        ]);
        $this->createIndex(null, '{{%portero_identities}}', ['connectionUid', 'subjectHash'], true);
        $this->addForeignKey(null, '{{%portero_identities}}', 'userId', Table::USERS, 'id', 'CASCADE');

        $this->createTable('{{%portero_attempts}}', [
            'id' => $this->primaryKey(),
            'connectionUid' => $this->char(36)->notNull(),
            'protocol' => $this->string(8)->notNull(),
            'stage' => $this->string(16)->notNull(),
            'outcome' => $this->string(16)->notNull(),
            'reasonCode' => $this->string(32),
            'maskedEmail' => $this->string(255),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, '{{%portero_attempts}}', ['dateCreated']);

        $this->createTable('{{%portero_flows}}', [
            'id' => $this->primaryKey(),
            'connectionUid' => $this->char(36)->notNull(),
            'kind' => $this->string(8)->notNull(),
            'returnPath' => $this->string(1024),
            'requestId' => $this->string(64)->notNull(),
            'nonce' => $this->string(64),
            'codeVerifier' => $this->string(96),
            'bindingHash' => $this->char(64)->notNull(),
            'expiresAt' => $this->integer()->notNull(),
            'usedAt' => $this->integer(),
        ]);
        $this->createIndex(null, '{{%portero_flows}}', ['requestId'], true);

        $this->createTable('{{%portero_replay}}', [
            'id' => $this->primaryKey(),
            'assertionHash' => $this->char(64)->notNull(),
            'expiresAt' => $this->integer()->notNull(),
        ]);
        $this->createIndex(null, '{{%portero_replay}}', ['assertionHash'], true);

        $this->createTable('{{%portero_sessions}}', [
            'id' => $this->primaryKey(),
            'connectionUid' => $this->char(36)->notNull(),
            'nameHash' => $this->char(64)->notNull(),
            'refHash' => $this->char(64),
            'craftToken' => $this->string(100)->notNull(),
            'userId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateEnded' => $this->dateTime(),
        ]);
        $this->createIndex(null, '{{%portero_sessions}}', ['connectionUid', 'nameHash']);
        $this->createIndex(null, '{{%portero_sessions}}', ['craftToken']);
        $this->addForeignKey(null, '{{%portero_sessions}}', 'userId', Table::USERS, 'id', 'CASCADE');

        $this->createTable('{{%portero_state}}', [
            'connectionUid' => $this->char(36)->notNull(),
            'lastTestPassed' => $this->integer(),
            'metadata' => $this->text(),
            'metadataFetched' => $this->integer(),
            'metadataFailed' => $this->integer(),
            'certificatesChanged' => $this->integer(),
            'PRIMARY KEY([[connectionUid]])',
        ]);
        return true;
    }

    public function safeDown(): bool
    {
        foreach (['state', 'sessions', 'replay', 'flows', 'attempts', 'identities'] as $table) {
            $this->dropTableIfExists("{{%portero_$table}}");
        }
        return true;
    }
}
