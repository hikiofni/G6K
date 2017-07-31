<?php

/*
The MIT License (MIT)

Copyright (c) 2017 Jacques Archimède

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

namespace EUREKA\G6KBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use EUREKA\G6KBundle\Entity\Simulator;
use EUREKA\G6KBundle\Entity\DataGroup;
use EUREKA\G6KBundle\Entity\Data;
use EUREKA\G6KBundle\Entity\FieldSet;
use EUREKA\G6KBundle\Entity\FieldRow;
use EUREKA\G6KBundle\Entity\Field;
use EUREKA\G6KBundle\Entity\Step;

use EUREKA\G6KBundle\Manager\ControllersHelper;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

class APIController extends BaseController {

	private $datas = array();
	private $metas = array();
	private $errors = array();

	public function calculAction(Request $request, $simu)
	{
		return $this->runCalcul($request, $simu);
	}

	public function tryItAction(Request $request, $simu)
	{
		return $this->runCalcul($request, $simu, true);
	}

	protected function runCalcul(Request $request, $simu, $test = false)
	{
		$this->helper = new ControllersHelper($this, $this->container);
		try {
			$api = $this->container->getParameter('api');
		} catch (\Exception $e) {
			throw $this->createNotFoundException($this->get('translator')->trans("API for this simulator is not implemented"));
		}
		if (! is_array($api) || !isset($api[$simu])) {
			throw $this->createNotFoundException($this->get('translator')->trans("API for this simulator is not implemented"));
		}
		$form = $request->query->all();
		$form['step'] = $api[$simu]['step'];
		$form[$api[$simu]['action']] = 1;
		try {
			$step = $this->runStep($request, $form, $simu, $view, $test);
		} catch (\Exception $e) {
		}
		if (!is_null($step) && ! $step instanceof Step) {
			return $step;
		}
		return $this->apiOutput($request, $form, $step);
	}

	protected function apiOutput(Request $request, $form, Step $step)
	{
		$fields = array_fill_keys(preg_split('/\s*,\s*/', $request->query->get('fields', '')), 1);
		foreach ($fields as $field => $val) {
			if ($field != '') {
				$data = $this->simu->getDataByName($field);
				if (is_null($data)) {
					$this->addParameterError(
						$field,
						$this->get('translator')->trans("Invalid fields parameter"), 
						$this->get('translator')->trans("This field doesn't exists")
					);
				} else {
					$this->datas[$data->getName()] = $data->getValue();
					$this->metas[$data->getName()] = $data->getLabel();
				}
			}
		}
		$actionButton = "";
		if (! isset($form['step'])) {
			$this->addParameterError(
				'step',
				$this->get('translator')->trans("Invalid step parameter"), 
				$this->get('translator')->trans("The step parameter is required")
			);
		} else {
			$cstep = $this->simu->getStepById($form['step']);
			if (! is_null($cstep)) {
				foreach ($cstep->getActions() as $action) {
					$name = $action->getName();
					if (isset($form[$name]) && $action->getWhat() == 'submit') {
						$actionButton = $name;
						break;
					}
				}
				if ($actionButton == "") {
					$this->addEntityError(
						"/data/" . $this->simu->getName(),
						$this->get('translator')->trans("Missing action parameter"), 
						$this->get('translator')->trans("The action parameter is required")
					);
				}
			}
		}
		foreach ($form as $param => $val) {
			if ($param != 'fields' && $param != 'step' && $param != $actionButton) {
				$data = $this->simu->getDataByName($param);
				if (is_null($data)) {
					$this->addParameterError(
						$param,
						$this->get('translator')->trans("Invalid parameter"), 
						$this->get('translator')->trans("This parameter doesn't exists")
					);
				}
			}
		}
		if ($this->simu->isError()) {
			$this->addEntityError(
				"/data/" . $this->simu->getName(),
				$this->get('translator')->trans("Global error"), 
				implode("\n", $this->simu->getErrorMessages())
			);
		}
		if (is_null($step)) {
			$this->addParameterError(
				'step',
				$this->get('translator')->trans("Invalid step"), 
				$this->get('translator')->trans("This step doesn't exists")
			);
		} else {
			foreach ($step->getPanels() as $panel) {
				if ($panel->isDisplayable()) {
					foreach ($panel->getFieldSets() as $block) {
						if ($block instanceof FieldSet) {
							$fieldset = $block;
							if ($fieldset->isDisplayable()) {
								foreach ($fieldset->getFields() as $child) {
									if ($child instanceof Field) {
										$this->processApiField($form, $child);
									} elseif ($child instanceof FieldRow) {
										$fieldrow = $child;
										foreach ($fieldrow->getFields() as $field) {
											$this->processApiField($form, $field);
										}
									}
								}
							}
						}
					}
				}
			}
		}
		$id = urlencode(base64_encode( gzcompress($request->getQueryString())));
		// steps to get the query string from the id :
		// 1. urldecode the id
		// 2. base64_decode the result
		// 3. gzuncompress the result
		// 4. urldecode the result
		$self = $request->getSchemeAndHttpHost() . $request->getBasePath() . $request->getPathInfo() . '?' . $request->getQueryString();
		$response = new Response();
		$response->headers->set('Content-Type', 'application/json');
		$content = array(
			'links' => array(
				'self' => $self
			)
		);
		if ($this->error) {
			$content['errors'] = $this->errors;
			$response->setStatusCode(Response::HTTP_BAD_REQUEST);
		}
		$content['data'] = array(
			'type' => $this->simu->getName(),
			'id' => $id,
			'attributes' => $this->datas,
			'meta' => $this->metas
		);
		$response->setContent(json_encode($content));
		return $response;
	}

	private function processApiField($form, Field $field) {
		if ($field->isDisplayable()) {
			$id = $field->getData();
			$data = $this->simu->getDataById($id);
			if ($data instanceof DataGroup) {
				if ($data->isError()) {
					$this->addResponseError($form, $data);
				}
				foreach ($data->getDatas() as $gdata) {
					$this->processApiFieldData($form, $gdata);
				}
			} elseif ($data instanceof Data) {
				$this->processApiFieldData($form, $data);
			}
		}
	}

	private function processApiFieldData($form, Data $data) {
		$this->datas[$data->getName()] = $data->getValue();
		$this->metas[$data->getName()] = $data->getLabel();
		if ($data->isError()) {
			$this->addResponseError($form, $data);
		}
	}

	private function addResponseError($form, Data $data) {
		$name = $data->getName();
		if (isset($form[$name])) {
			$this->addParameterError(
				$name,
				$this->get('translator')->trans("Invalid parameter"), 
				implode("\n", $data->getErrorMessages())
			);
		} else {
			$this->addEntityError(
				"/data/attribute/" . $name,
				$this->get('translator')->trans("Error on data"), 
				implode("\n", $data->getErrorMessages())
			);
		}
	}

	private function addParameterError($parameter, $title, $detail) {
		$this->errors[] = array(
			'status' => "" . Response::HTTP_BAD_REQUEST,
			'title' => $title,
			'detail' => $detail,
			'source' => array(
				'parameter' => $parameter
			)
		);
		$this->error = true;
	}

	private function addEntityError($entity, $title, $detail) {
		$this->errors[] = array(
			'status' => "" . Response::HTTP_UNPROCESSABLE_ENTITY,
			'title' => $title,
			'detail' => $detail,
			'source' => array(
				'pointer' => $parameter
			)
		);
		$this->error = true;
	}

}

?>
