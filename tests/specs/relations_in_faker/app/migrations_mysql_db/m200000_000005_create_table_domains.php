<?php

/**
 * Table for Domain
 */
class m200000_000005_create_table_domains extends \yii\db\Migration
{
    public function up()
    {
        $this->createTable('{{%domains}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(128)->notNull(),
            'account_id' => $this->integer()->notNull(),
            0 => 'created_at datetime NOT NULL',
        ]);
        $this->addForeignKey('fk_domains_account_id_accounts_id', '{{%domains}}', 'account_id', '{{%accounts}}', 'id');
    }

    public function down()
    {
        $this->dropForeignKey('fk_domains_account_id_accounts_id', '{{%domains}}');
        $this->dropTable('{{%domains}}');
    }
}
