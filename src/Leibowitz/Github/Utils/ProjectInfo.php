<?php

namespace Leibowitz\Github\Utils;

use Guzzle\Http;

class ProjectInfo
{
    public function __construct($config)
    {
        $this->config = $config;

        $this->founds = array();

        $this->initGithubClient();
    }

    function initGithubClient()
    {
        // Authentification
        $this->githubclient = new Github\Client();

        $this->githubclient->authenticate(
            $this->config['token'],
            '',
            Github\Client::AUTH_HTTP_TOKEN);

    }

    function getGithubClient()
    {
        return $this->githubclient;
    }

    function hasProject($key)
    {
        return array_key_exists($key, $this->config['projects']);
    }

    function getProjectConfig($key = null)
    {
        return $this->config['projects'][ $key ?: $this->project_key ];
    }

    function setProjectKey($key)
    {
        $this->project_key = $key;
    }

    function getProjectCommit($project_key = null)
    {
        $project_data = $this->getProjectConfig($project_key);

        $url = $project_data['url'];
        $field = $project_data['field'];

        // find commit hash of deployed version
        $httpclient = new Http\Client($url);

        $request = $httpclient->get();
        $resp = $request->send();

        $content = json_decode($resp->getBody(), true);

        return $content[ $field ];
    }

    function getCommitDetails($commit, $project_key = null)
    {
        // start to query the github api about the commit
        // get info about the commit
        $project = $this->getProjectConfig($project_key);
        $commit_details = $this->getGithubClient()
            ->api('repo')
            ->commits()
            ->show($this->config['user'], $project['name'], $commit);

        return array(
            'author' => $commit_details['commit']['author']['name'],
            'date' => $commit_details['commit']['author']['date'],
            'message' => $commit_details['commit']['message'],
            'url' => $commit_details['html_url']
        );
    }

    function getBranches($project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('repo')
            ->branches($this->config['user'], $project['name']);
    }

    function addFoundBranch($branch, $index = 0)
    {
        // store the branches names where the commit was found
        $this->founds[ $branch ] = $index;
    }

    function getFoundBranches()
    {
        return array_keys($this->founds);
    }

    function countFoundBranches()
    {
        return count($this->founds);
    }

    function getPullRequests(
        $branch = 'master',
        $state = 'closed',
        $project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('pull_request')
            ->all(
                $this->config['user'],
                $project['name'],
                array(
                    'state' => $state,
                    'base' => $branch,
                    // Get only latest 3 PR as otherwise github api
                    // tends to timeout
                    'per_page' => 3,
                ));
    }

    function getAllBranchesSha($project_key = null)
    {
        $data = array();

        $branches = $this->getBranches($project_key);

        foreach($branches as $branch) {
            $data[ $branch['name'] ] = $branch['commit']['sha'];
        }

        $pullrequests = $this->getPullRequests(
            'master',
            'closed',
            $project_key);

        foreach($pullrequests as $preq) {
            $data[ $preq['head']['ref'] ] = $preq['head']['sha'];
        }

        return $data;
    }

    public function compareSha($branches, $commit)
    {
        foreach($branches as $branch => $sha) {
            if( $sha == $commit ) {
                $this->addFoundBranch( $branch );
            }
        }
    }

    public function getBranchesForCommit($commit, $project_key = null)
    {
        $branches = $this->getAllBranchesSha($project_key);

        // Don't check master just yet
        $master_branch = array('master' => $branches['master']);

        unset($branches['master']);

        // Look if we can find the commit at the top of a branch
        $this->compareSha($branches, $commit);

        // Compare with master
        $this->compareSha($master_branch, $commit);

        if( $this->countFoundBranches() == 0 ) {
            // Search in previous commits in master
            $this->searchBranches($master_branch, $commit, $project_key);
        }

        if( $this->countFoundBranches() == 0 ) {
            // Search in previous commits in all other branches
            $this->searchBranches($branches, $commit, $project_key);
        }

        return $this->getFoundBranches();
    }

    public function getBranchCommits($sha, $project_key = null)
    {
        $project = $this->getProjectConfig($project_key);
        return $this->getGithubClient()
            ->api('repo')
            ->commits()
            ->all(
                $this->config['user'],
                $project['name'],
                array('sha' => $sha) );
    }

    public function searchBranches($branches, $search_commit, $project)
    {
        foreach($branches as $branch => $sha) {
            $commits = $this->getBranchCommits($sha, $project);

            foreach($commits as $index => $commit) {
                if( $search_commit == $commit['sha'] ) {
                    // We found the commit in this branch
                    $this->addFoundBranch( $branch, $index );
                    break 2;
                }
            }
        }

    }

    public function getProjectInfo($project)
    {
        $commit = $this->getProjectCommit($project);

        $details = $this->getCommitDetails($commit, $project);

        $details['branches'] = $this->getBranchesForCommit($commit, $project);

        return $details;
    }
}
