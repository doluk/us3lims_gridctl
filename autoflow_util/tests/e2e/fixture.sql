-- fixture for uslims3_e2e: one run, 3 datasets (cell 1 A/B standard, cell 2 A band forming)
SET FOREIGN_KEY_CHECKS=0;
INSERT INTO people SET personID=12, personGUID='aaaaaaaa-0000-0000-0000-000000000012', fname='Ada', lname='Lovelace',
  email='ada@example.org', phone='1', userlevel=2, advancelevel=0, clusterAuthorizations='us3iab-node0',
  password='x', activated=1, userNamePAM='';
INSERT INTO rotorCalibration SET rotorCalibrationID=1, rotorCalibrationGUID=UUID(), coeff1=1.5e-6, coeff2=2.5e-12;
INSERT INTO experiment SET experimentID=1, runID='run1', instrumentID=1, dateBegin='2026-01-01', rotorCalibrationID=1;
INSERT INTO speedstep SET experimentID=1, scans=50, durationhrs=5, durationmins=0, delayhrs=0, delaymins=1,
  rotorspeed=50000, acceleration=400, w2tfirst=1e9, w2tlast=9e10, timefirst=100, timelast=18000,
  setspeed=50000, avgspeed=50000, speedsdev=1;
INSERT INTO abstractCenterpiece SET abstractCenterpieceID=1, name='std', channels=2, bottom='7.2', shape='standard', angle=2.5, pathLength=1.2, width=0;
INSERT INTO abstractCenterpiece SET abstractCenterpieceID=2, name='band', channels=2, bottom='7.2', shape='band forming', angle=2.5, pathLength=1.2, width=0;
INSERT INTO cell SET experimentID=1, name='1', abstractCenterpieceID=1;
INSERT INTO cell SET experimentID=1, name='2', abstractCenterpieceID=2;
INSERT INTO rawData SET rawDataID=101, rawDataGUID=UUID(), experimentID=1, solutionID=1, channelID=1, filename='run1.RA.1.A.280.auc', data='AUC-A';
INSERT INTO rawData SET rawDataID=102, rawDataGUID=UUID(), experimentID=1, solutionID=1, channelID=2, filename='run1.RA.1.B.280.auc', data='AUC-B';
INSERT INTO rawData SET rawDataID=103, rawDataGUID=UUID(), experimentID=1, solutionID=1, channelID=3, filename='run1.RA.2.A.280.auc', data='AUC-C';
INSERT INTO editedData SET editedDataID=555, rawDataID=101, editGUID=UUID(), filename='run1.e1.RA.1.A.280.xml', data='<edit/>';
INSERT INTO editedData SET editedDataID=556, rawDataID=102, editGUID=UUID(), filename='run1.e1.RA.1.B.280.xml', data='<edit/>';
INSERT INTO editedData SET editedDataID=557, rawDataID=103, editGUID=UUID(), filename='run1.e1.RA.2.A.280.xml', data='<edit/>';
-- custom grid model
INSERT INTO model SET modelID=4711, modelGUID=UUID(), editedDataID=555, description='run1.CustomGrid-model', xml='<ModelData><model><analyte signal="0.5"/></model></ModelData>';
-- noises selected in "queue setup"
INSERT INTO noise SET noiseID=77, noiseGUID=UUID(), editedDataID=555, modelID=4711, noiseType='ti_noise', xml='<noise/>';
SET FOREIGN_KEY_CHECKS=1;
