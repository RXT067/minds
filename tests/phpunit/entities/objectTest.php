<?php

class objectTest extends Minds_PHPUnit_Framework_TestCase {

        protected function setUp() {
                $this->setUser();
        }

        public function testCanConstructWithoutArguments() {
                $this->assertNotNull(new Minds\Entities\object());
        }

		public function testSave(){
			$object = new Minds\Entities\object();
			$object->title = "object test";
			$object->description = "";
			//$this->assertInternalType('string', $object->save());
		}

		public function testLoadFromGuid(){
		/*	$object = new Minds\Entities\object();
			$object->title = "object test";
			$object->description = "";
			$guid = $object->save();
			invalidate_cache_for_entity($guid);

			$object = new Minds\Entities\object($guid);
			$this->assertEquals('object test', $object->title);
			$this->assertInternalType('array',$object->ownerObj);
			$this->assertInstanceOf('ElggUser', $object->getOwnerEntity());*/
		}
}
