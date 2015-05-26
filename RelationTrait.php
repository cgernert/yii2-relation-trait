<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace mootensai\relation;
trait RelationTrait{
    
    public function loadWithRelation($POST) {
        if ($this->load($POST)) {
            $reflector = new \ReflectionClass($this);
            $shortName = $reflector->getShortName();
            foreach ($POST as $key => $value) {
                if ($key != $shortName && strpos($key, '_') === false) {
                    $isHasMany = is_array($value);
                    $relName = ($isHasMany) ? lcfirst($key) . 's' : lcfirst($key);
                    $rel = $this->getRelation($relName);
                    if ($isHasMany) {
                        $container = [];
                        foreach ($value as $relPost) {
                            /* @var $relObj \yii\db\ActiveRecord */
                            $relObj = new $rel->modelClass;
                            $relObj->load($relPost, '');
                            $container[] = $relObj;
                        }
                        $this->populateRelation($relName, $container);
                    } else {
                        $relObj = new $rel->modelClass;
                        $relObj->load($value);
                        $this->populateRelation($relName, $value);
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }
    
    public function saveWithRelation() {
        /* @var $this ActiveRecord */
        $db = $this->getDb();
        $trans = $db->beginTransaction();
        try {
            if ($this->save()) {
                $error = 0;
                foreach ($this->relatedRecords as $name => $records) {
                    $AQ = $this->getRelation($name);
                    $link = $AQ->link;
                    $notDeletedFK = [];
                    $notDeletedPK = [];
                    $relPKAttr = $records[0]->primaryKey();
                    $isCompositePK = (count($relPKAttr) > 1);
                    /* @var $relModel ActiveRecord */
                    foreach($records as $index => $relModel){
                        foreach ($link as $key => $value){
                            $relModel->$key = $this->$value;
                            $notDeletedFK[] = "$key = '{$this->$value}'";
                        }
                        if(!$relModel->save()){
                            $relModelWords = Inflector::camel2words(StringHelper::basename($AQ->modelClass));
                            $index++;
                            foreach ($relModel->errors as $validation){
                                foreach($validation as $errorMsg){
                                    $this->addError($name,"$relModelWords #$index : $errorMsg");
                                }
                            }
                            $error = 1;
                        }else{
                            //GET PK OF REL MODEL
                            if($isCompositePK){
                                foreach($relModel->primaryKey as $attr => $value){
                                    $notDeletedPK[$attr][] = "'$value'";
                                }
                            }  else {
                                $notDeletedPK[] = $relModel->primaryKey;
                            }
                        }
                    }
                    //DELETE WITH 'NOT IN' PK MODEL & REL MODEL
                    $notDeletedFK = implode(' AND ', $notDeletedFK);
                    if($isCompositePK){
                        $compiledNotDeletedPK = [];
                        foreach($notDeletedPK as $attr => $pks){
                            $compiledNotDeletedPK[$attr] = "$attr NOT IN(".implode(', ', $pks).")";
//                            echo "$notDeletedFK AND ".implode(' AND ', $compiledNotDeletedPK);
                            $relModel->deleteAll("$notDeletedFK AND ".implode(' AND ', $compiledNotDeletedPK));
                        }
                    }else{
                        $relModel->deleteAll($notDeletedFK.' AND '.$relPKAttr[0]." NOT IN ($notDeletedFK)");
                    }
                }
                if ($error) {
                    $trans->rollback();
                    return false;
                }
                $trans->commit();
                return true;
            } else {
                return false;
            }
        } catch (Exception $exc) {
            $trans->rollBack();
            throw $exc;
        }
    }
}